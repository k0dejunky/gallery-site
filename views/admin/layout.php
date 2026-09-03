<?php
// Admin chrome: sidebar navigation + shared CSS + flash messages. Views are
// injected as $content so each admin page keeps a single shared shell.
// NOTE: never assign $user here — viewAdmin() has already extracted the
// page's own $user (e.g. the account being viewed) into this scope, and an
// assignment here would silently replace it with the logged-in admin.
$flash = \App\Core\Flash::all();
\App\Core\Flash::clear();

$current = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$base    = rtrim(url('/'), '/');

// Marks the sidebar item "active" when the current path matches the href
// (exact match for the dashboard, prefix match for section pages).
$navActive = static function (string $href, bool $exact = false) use ($current, $base): string {
    if ($exact) {
        return $current === $base . $href ? 'active' : '';
    }

    return $current === $base . $href || strpos($current, $base . $href . '/') === 0 ? 'active' : '';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($title) ? e($title) . ' — ' . config('app.site_name') . ' Admin' : e(config('app.site_name')) . ' Admin' ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/admin-shared.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/user.css')) ?>?v=3">
    <style>
<?= \App\Models\Theme::css(\App\Models\Theme::SCOPE_ADMIN) ?>
<?= \App\Models\Theme::cssLayout(\App\Models\Theme::SCOPE_ADMIN) ?>
        body { font-family: sans-serif; max-width: none; width: 100%; margin: 0; padding: 1rem 1.5rem; box-sizing: border-box; color: var(--purple-900); background: var(--pink-200); font-size: var(--font-size-base); line-height: var(--line-height); }
        a { color: var(--purple-600); }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], textarea, select { padding: 0.4rem 0.6rem; border: 1px solid var(--pink-300); border-radius: 4px; background: var(--pink-100); color: var(--purple-900); }
        input:focus, textarea:focus, select:focus { outline: 2px solid var(--purple-400); }
        .title-header { text-align: center; margin-bottom: var(--spacing-lg); }
        .title-header img { max-width: 480px; height: auto; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
        .admin-shell { display: flex; gap: var(--spacing-lg); align-items: flex-start; }
        .admin-nav { flex: 0 0 230px; display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--spacing-md); background: var(--sidebar-bg); border: var(--input-border-width) solid var(--sidebar-border); border-radius: var(--border-radius-lg); position: sticky; top: 0; }
        .admin-nav .nav-brand { font-weight: bold; font-size: var(--font-size-lg); color: var(--sidebar-heading); text-decoration: none; margin: 0 0 var(--spacing-sm); }
        .admin-nav .nav-item { display: block; padding: var(--spacing-sm) var(--card-padding); border-radius: var(--border-radius); color: var(--sidebar-link-color); text-decoration: none; background: var(--sidebar-link-bg); border: var(--input-border-width) solid var(--sidebar-link-border); }
        .admin-nav .nav-item:hover { background: var(--sidebar-link-hover); }
        .admin-nav .nav-item.active { background: var(--sidebar-active-bg); border-color: var(--sidebar-active-border); color: var(--sidebar-active-color); }
        .admin-nav .nav-sep { border-top: var(--input-border-width) solid var(--sidebar-border); margin: var(--spacing-sm) 0; }
        .admin-nav .nav-logout { width: 100%; }
         .admin-nav .nav-logout button { width: 100%; background: var(--btn-danger-bg); color: var(--btn-danger-color); border: var(--input-border-width) solid var(--btn-danger-border); padding: var(--spacing-xs) var(--spacing-sm); font-size: var(--font-size-sm); }
         .admin-nav .nav-logout button:hover { background: var(--btn-danger-hover-bg); }
         .nav-top-content { margin-bottom: var(--spacing-xs); }
        /* Theme scope tabs follow the sidebar palette so they always match the
           active theme, regardless of where the block sits in the menu. */
        .admin-nav .theme-tabs { display: inline-flex; gap: var(--spacing-xs); background: var(--sidebar-link-bg); border: var(--input-border-width) solid var(--sidebar-link-border); border-radius: var(--border-radius); padding: var(--spacing-xs); width: 100%; }
        .admin-nav .theme-tab { flex: 1; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: var(--border-radius); background: transparent; color: var(--sidebar-link-color); cursor: pointer; font-weight: bold; text-align: center; }
        .admin-nav .theme-tab:hover { background: var(--sidebar-link-hover); }
        .admin-nav .theme-tab.active { background: var(--sidebar-active-bg); border: var(--input-border-width) solid var(--sidebar-active-border); color: var(--sidebar-active-color); }
        .admin-nav .btn { text-align: left; }
        .admin-main { flex: 1; min-width: 0; }
        .btn { display: inline-block; padding: var(--btn-padding); background: var(--btn-bg); color: var(--btn-color); text-decoration: none; border-radius: var(--btn-radius); border: none; cursor: pointer; font-size: var(--btn-font-size); text-align: center; }
        .btn:hover { background: var(--btn-hover-bg); }
        .btn-danger { background: var(--btn-danger-bg); color: var(--btn-danger-color); border: var(--input-border-width) solid var(--btn-danger-border); }
        .btn-danger:hover { background: var(--btn-danger-hover-bg); }
        .btn-sm { padding: var(--spacing-xs) var(--spacing-sm); font-size: var(--font-size-sm); }
        .btn-outline { background: var(--pink-100); color: var(--purple-700); border: 1px solid var(--pink-400); }
        .btn-outline:hover { background: var(--pink-200); }
        form.inline { display: inline; }
        .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 0.75rem; margin: 1rem 0; }
        .bulk-photo-toolbar { display: flex; align-items: center; gap: var(--spacing-sm); flex-wrap: wrap; margin: 0 0 var(--spacing-sm); padding: var(--spacing-sm); background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: var(--border-radius); }
        .bulk-photo-toolbar label { display: inline-flex; align-items: center; gap: .35rem; font-size: var(--font-size-sm); }
        .bulk-photo-toolbar .btn:disabled { opacity: .5; cursor: not-allowed; }
        .admin-form { max-width: 480px; margin: 0 auto; }
        .admin-form input[type="text"], .admin-form textarea, .admin-form select { width: 100%; box-sizing: border-box; }
        .admin-form .type-row { text-align: center; margin-bottom: 1rem; }
        .admin-form .type-row .muted { display: block; margin-top: 0.35rem; }
        .admin-form .actions { display: flex; gap: 0.5rem; justify-content: center; text-align: center; margin-bottom: 0; }
        .admin-form .actions .btn { flex: 1; max-width: 12rem; text-align: center; }
        .chips-justify { justify-content: space-between; }
        .editor-grid { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; align-items: start; margin-top: 1rem; }
        .editor-preview { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; padding: 0.75rem; text-align: center; }
        .editor-preview img, .editor-preview video { max-width: 100%; border-radius: 8px; }
        .editor-preview .thumb-row { display: flex; gap: 0.75rem; align-items: flex-start; justify-content: center; margin-top: 0.75rem; flex-wrap: wrap; }
        .editor-preview figure { margin: 0; }
        .editor-tools { display: flex; flex-direction: column; gap: 0.75rem; }
        .canvas-editor { margin: 1rem 0; padding: 1rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; }
        .canvas-stage { min-height: 320px; display: grid; place-items: center; background: #24152d; border-radius: 8px; overflow: auto; position: relative; }
        .canvas-stage canvas { max-width: 100%; max-height: 70vh; display: block; }
        .crop-overlay { position: absolute; pointer-events: none; cursor: crosshair; }
        .crop-selection { position: absolute; border: 2px solid #fff; box-shadow: 0 0 0 9999px rgba(0, 0, 0, .55); }
        .crop-selection[hidden] { display: none; }
        .canvas-loading { color: #fff; }
        .canvas-toolbar { display: grid; gap: 0.8rem; margin-top: 0.8rem; }
        .canvas-actions, .canvas-controls { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
        .canvas-actions { justify-content: center; }
        .canvas-controls label { display: grid; gap: 0.25rem; min-width: 145px; font-size: 0.85rem; }
        .canvas-controls input[type="range"] { width: 145px; }
        .canvas-hint { margin-left: 0.5rem; font-size: 0.85rem; }
        .tool-box { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 8px; padding: 0.75rem; }
        .tool-box h3 { margin: 0 0 0.5rem; font-size: 0.95rem; }
        .tool-box label { display: block; margin: 0.35rem 0; font-size: 0.85rem; color: var(--purple-800); }
        .tool-box input[type="text"], .tool-box input[type="number"], .tool-box select, .tool-box input[type="file"] { width: 100%; box-sizing: border-box; margin-top: 0.15rem; }
        .tool-box input[type="color"] { width: 44px; height: 34px; padding: 2px; border: 1px solid var(--pink-400); border-radius: 6px; background: none; cursor: pointer; }
        .tool-box .btn { margin-top: 0.5rem; }
        .rotate-row { display: flex; gap: 0.4rem; }
        @media (max-width: 820px) { .editor-grid { grid-template-columns: 1fr; } }
        .media-item { display: flex; flex-direction: column; gap: 0.3rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 8px; padding: 0.4rem; }
        .media-item img, .media-item video { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 6px; background: var(--purple-900); }
        .media-name { font-size: 0.7rem; color: var(--purple-700); word-break: break-all; text-align: center; }
        table { border-collapse: collapse; width: 100%; background: var(--pink-100); }
        th, td { border: 1px solid var(--pink-300); padding: 0.5rem; text-align: left; vertical-align: middle; color: var(--purple-900); }
        th { background: var(--pink-300); color: var(--purple-900); }
        .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; color: var(--purple-900); }
        .flash.error { background: var(--pink-300); border-left: 4px solid var(--pink-600); }
        .flash.success { background: var(--pink-200); border-left: 4px solid var(--purple-500); }
        .log-diff { margin-top: 0.35rem; display: grid; gap: 0.15rem; }
        .log-diff-line { font-size: 0.85rem; line-height: 1.4; }
        .log-diff-line .diff-field { font-weight: bold; color: var(--purple-800); }
        .log-diff-line .diff-before { color: var(--purple-700); text-decoration: line-through; opacity: 0.8; }
        .log-diff-line .diff-arrow { color: var(--purple-500); margin: 0 0.2rem; }
        .log-diff-line .diff-after { font-weight: bold; color: var(--purple-900); }
        .pagination { display: flex; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 0.35rem 0.7rem; border: 1px solid var(--pagination-border); border-radius: 4px; text-decoration: none; color: var(--pagination-color); background: var(--pagination-bg); }
        .pagination a:hover { background: var(--pagination-hover-bg); }
        .pagination .current { background: var(--pagination-active-bg); color: var(--pagination-active-color); border-color: var(--pagination-border); }
        .admin-main table td:last-child { text-align: center; }
        .chips { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1rem 0; align-items: center; }
        .chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border: 1px solid var(--pink-400); border-radius: 999px; background: var(--pink-300); color: var(--purple-700); text-decoration: none; }
        .chip a { text-decoration: none; color: inherit; }
        .chip:hover { background: var(--filter-hover-bg); }
        .chip.active:hover { background: var(--filter-hover-bg); }
        .chip:has(input[type="checkbox"]) { cursor: pointer; }
        .chip input[type="checkbox"] { display: none; }
        .chip:has(input[type="checkbox"]:checked) { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .favorite-option.selected { background: var(--filter-bg); border-color: var(--filter-border); color: var(--filter-color); }
        .favorite-option.selected:hover { background: var(--filter-hover-bg); }
        .favorite-option:not(.selected) { background: var(--filter-inactive-bg); border-color: var(--filter-inactive-border); color: var(--filter-inactive-color); }
        .favorite-option:not(.selected):hover { background: var(--filter-inactive-hover); }
        .filter-inactive { background: var(--filter-inactive-bg); border-color: var(--filter-inactive-border); color: var(--filter-inactive-color); }
        .filter-inactive:hover { background: var(--filter-inactive-hover); }
        .section-title { color: var(--purple-800); border-bottom: 2px solid var(--pink-300); padding-bottom: 0.35rem; margin-top: 1.5rem; }
        .muted { color: var(--purple-800); opacity: 0.75; }
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin: 1rem 0 1.5rem; align-items: start; }
        .stats-stack { display: flex; flex-direction: column; gap: 1.25rem; }
        .stats-period { display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0 0.5rem; font-size: 0.9rem; color: var(--purple-700); }
        .stats-period select { padding: 0.35rem 0.6rem; border: 1px solid var(--pink-300); border-radius: 4px; background: var(--pink-100); color: var(--purple-900); cursor: pointer; }
        .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.75rem; margin: 1rem 0 0.25rem; }
        .stat-card { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; padding: 0.9rem 1rem; }
        .stat-card b { display: block; font-size: 1.5rem; color: var(--purple-900); }
        .stat-card small { color: var(--purple-700); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.7rem; }
        .stats-panel { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; padding: 0.75rem 1rem; }
        .stats-panel h2 { margin: 0.25rem 0 0.5rem; font-size: 1.05rem; color: var(--purple-800); border-bottom: 1px solid var(--pink-300); padding-bottom: 0.35rem; }
        .stats-panel table { margin-top: 0.5rem; }
        .trend { font-weight: bold; white-space: nowrap; }
        .trend-up { color: #1d7a3a; }
        .trend-down { color: #b3261e; }
        .trend-new { color: var(--purple-500); }
        .trend-flat { color: var(--purple-700); opacity: 0.6; }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: 1fr; } }
        .theme-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; margin: 1rem 0; }
        .theme-swatch { display: grid; grid-template-columns: 75px 1fr 44px auto; align-items: center; gap: 0.6rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 8px; padding: 0.6rem; }
        .theme-swatch .theme-name { grid-column: 1; grid-row: 1; font-size: 0.85rem; color: var(--purple-800); }
        .theme-swatch input[type="color"] { grid-column: 3; grid-row: 1; width: 44px; height: 34px; border: 1px solid var(--pink-400); border-radius: 6px; background: none; padding: 2px; cursor: pointer; }
        .theme-swatch code { grid-column: 4; grid-row: 1; font-size: 0.8rem; color: var(--purple-700); }
        .theme-swatch .theme-description { grid-column: 1 / -1; grid-row: 2; font-size: 0.8rem; color: var(--purple-700); }
        pre { background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 8px; padding: 1rem; overflow-x: auto; color: var(--purple-900); }
        code { background: var(--pink-100); padding: 0.1rem 0.35rem; border-radius: 4px; }
        h1, h2, h3 { color: var(--purple-800); text-align: center; }

        /* Theme choice cards & preview (for settings page) */
        .theme-choice-layout { display: grid; grid-template-columns: minmax(220px, 0.8fr) minmax(0, 1.4fr); gap: var(--spacing-lg); align-items: start; }
        .theme-choice-list { display: grid; gap: var(--spacing-sm); }
        .theme-choice-card { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--card-padding); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--card-radius); background: var(--pink-100); color: var(--purple-900); cursor: pointer; }
        .theme-choice-card:hover, .theme-choice-card.selected { border-color: var(--purple-400); background: var(--pink-200); }
        .theme-choice-card input { position: absolute; opacity: 0; pointer-events: none; }
        .theme-choice-card span { font-size: var(--font-size-xs); color: var(--purple-700); }
        .theme-choice-preview-label { margin-bottom: var(--spacing-xs); color: var(--purple-700); font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; }
        .theme-choice-preview { padding: var(--spacing-md); background: var(--pink-200); color: var(--purple-900); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
        .theme-choice-preview > img { display: block; max-width: 180px; max-height: 52px; margin: 0 auto var(--spacing-sm); border-radius: var(--border-radius); }
        .theme-choice-preview-nav { display: flex; gap: var(--spacing-md); align-items: center; flex-wrap: wrap; padding-bottom: var(--spacing-sm); margin-bottom: var(--spacing-md); border-bottom: var(--input-border-width) solid var(--pink-300); }
        .theme-choice-preview-nav b { color: var(--purple-900); }
        .theme-choice-preview-nav a, .theme-choice-preview-card a { color: var(--purple-600); text-decoration: none; }
        .theme-choice-preview-nav a:hover, .theme-choice-preview-card a:hover { color: var(--purple-400); }
        .theme-choice-preview-body { display: grid; grid-template-columns: 130px 1fr; gap: var(--spacing-md); }
        .theme-choice-preview aside { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--spacing-sm); background: var(--sidebar-bg); border: var(--input-border-width) solid var(--sidebar-border); border-radius: var(--border-radius); }
        .theme-choice-preview aside b { color: var(--sidebar-heading); font-size: var(--font-size-xs); }
        .theme-choice-preview aside span { padding: var(--spacing-xs); background: var(--sidebar-link-bg); border: var(--input-border-width) solid var(--sidebar-link-border); border-radius: var(--border-radius); color: var(--sidebar-link-color); font-size: var(--font-size-xs); }
        .theme-choice-preview-card { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--card-padding); margin-bottom: var(--spacing-sm); background: var(--card-bg); border: var(--input-border-width) solid var(--card-border); border-radius: var(--card-radius); }
        .theme-choice-preview-card b { color: var(--card-title-color); }
        .theme-choice-preview-card small { color: var(--card-text-color); }
        .theme-choice-preview button { padding: var(--btn-padding); background: var(--btn-bg); color: var(--btn-color); border: 0; border-radius: var(--btn-radius); cursor: pointer; font-size: var(--btn-font-size); }
        .theme-choice-preview button:hover { background: var(--btn-hover-bg); }
        @media (max-width: 768px) { .theme-choice-layout { grid-template-columns: 1fr; } }

        /* Global progress / busy overlay */
        .progress-overlay {
            position: fixed; inset: 0; z-index: 10000;
            display: none; align-items: center; justify-content: center;
            background: rgba(20, 12, 30, 0.55); backdrop-filter: blur(1px);
        }
        .progress-overlay.active { display: flex; }
        .progress-box {
            width: min(340px, 86vw); background: var(--pink-100); color: var(--purple-900);
            border: 1px solid var(--purple-400); border-radius: 12px;
            padding: 1.25rem 1.5rem; box-shadow: 0 10px 40px rgba(0,0,0,.35);
            text-align: center;
        }
        .progress-box .pb-label { font-weight: 600; margin-bottom: .4rem; }
        .progress-box .pb-sub { font-size: .85rem; color: var(--purple-700); margin-bottom: .9rem; min-height: 1.1em; }
        .progress-track { height: 10px; border-radius: 999px; background: var(--pink-300); overflow: hidden; }
        .progress-fill {
            height: 100%; width: 0%; border-radius: 999px; background: var(--purple-500);
            transition: width .2s ease;
        }
        .progress-box .pb-percent { margin-top: .5rem; font-size: .85rem; color: var(--purple-800); }
        .progress-box .pb-pulse { margin-top: .8rem; font-size: .85rem; color: var(--purple-700); }
        .pb-spinner {
            width: 22px; height: 22px; margin: 0 auto .6rem;
            border: 3px solid var(--pink-300); border-top-color: var(--purple-500);
            border-radius: 50%; animation: pb-spin .8s linear infinite;
        }
        @keyframes pb-spin { to { transform: rotate(360deg); } }

        /* ---- Mobile navigation drawer ---- */
        .nav-toggle { display: none; }
        .nav-backdrop { display: none; }
        @media (max-width: 900px) {
            .admin-shell { display: block; }
            .admin-nav {
                position: fixed; top: 0; left: 0; bottom: 0; z-index: 900;
                width: 250px; max-width: 82vw; overflow-y: auto;
                border-radius: 0 var(--border-radius-lg) var(--border-radius-lg) 0;
                transform: translateX(-105%); transition: transform .22s ease;
                box-shadow: var(--shadow);
            }
            body.nav-open .admin-nav { transform: translateX(0); }
            .nav-backdrop {
                display: none; position: fixed; inset: 0; z-index: 890;
                background: rgba(20, 12, 30, .45);
            }
            body.nav-open .nav-backdrop { display: block; }
            .nav-toggle {
                display: inline-flex; align-items: center; justify-content: center;
                width: 2.4rem; height: 2.4rem; font-size: 1.35rem; line-height: 1;
                background: var(--sidebar-link-bg); color: var(--purple-700);
                border: 1px solid var(--pink-400); border-radius: var(--border-radius);
                cursor: pointer; margin-bottom: .75rem;
            }
            .admin-main { width: 100%; }
            body { padding: .75rem .9rem; }
            .stat-cards { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
            .quick-actions { justify-content: flex-start; }
        }

        /* ---- Quick actions bar ---- */
        .quick-actions {
            display: flex; flex-wrap: wrap; gap: .5rem; align-items: center;
            margin: 0 0 1rem; padding: .6rem .8rem;
            background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: var(--border-radius-lg);
        }
        .quick-actions .qa-label { font-size: var(--font-size-sm); color: var(--purple-700); font-weight: bold; margin-right: .25rem; }

        /* ---- Status pills ---- */
        .pill {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .1rem .55rem; border-radius: 999px;
            font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
            background: var(--pink-300); color: var(--purple-700);
            border: 1px solid var(--pink-400); white-space: nowrap;
        }
        .pill-ok   { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
        .pill-err  { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .pill-warn { background: #fffbeb; color: #b45309; border-color: #fde68a; }
        .pill-info { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .pill-muted{ background: var(--pink-200); color: var(--purple-800); border-color: var(--pink-300); }

        /* ---- Flash toasts ---- */
        .flash-stack {
            position: fixed; top: .9rem; right: .9rem; z-index: 950;
            display: flex; flex-direction: column; gap: .5rem; max-width: min(360px, 92vw);
        }
        .flash-toast {
            display: flex; align-items: flex-start; gap: .6rem;
            padding: .7rem .9rem; border-radius: var(--border-radius); box-shadow: var(--shadow);
            background: var(--pink-100); color: var(--purple-900);
            border-left: 4px solid var(--purple-500);
            animation: toast-in .25s ease;
        }
        .flash-toast.error { background: #fef2f2; border-left-color: #dc2626; color: #7f1d1d; }
        .flash-toast.success { background: #f0fdf4; border-left-color: #16a34a; color: #14532d; }
        .flash-toast .flash-close { margin-left: auto; background: none; border: none; cursor: pointer; color: inherit; opacity: .6; font-size: 1rem; line-height: 1; padding: 0 .1rem; }
        .flash-toast.hide { opacity: 0; transform: translateX(8px); transition: all .3s ease; }
        @keyframes toast-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }

        /* ---- Command palette ---- */
        .palette-overlay {
            position: fixed; inset: 0; z-index: 1000;
            display: flex; align-items: flex-start; justify-content: center; padding-top: 12vh;
            background: rgba(20, 12, 30, .5);
        }
        .palette-overlay[hidden] { display: none; }
        .palette {
            width: min(560px, 92vw); background: var(--pink-100); color: var(--purple-900);
            border: 1px solid var(--pink-400); border-radius: var(--border-radius-lg);
            box-shadow: 0 18px 50px rgba(0, 0, 0, .4); overflow: hidden;
        }
        .palette-input {
            width: 100%; box-sizing: border-box; padding: .9rem 1rem; font-size: 1rem;
            border: none; border-bottom: 1px solid var(--pink-300); background: var(--pink-200);
            color: var(--purple-900); outline: none;
        }
        .palette-list { max-height: 55vh; overflow-y: auto; padding: .4rem; }
        .palette-group { font-size: .72rem; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; color: var(--purple-600); padding: .55rem .6rem .2rem; }
        .palette-item {
            display: flex; align-items: center; gap: .6rem;
            padding: .5rem .6rem; border-radius: var(--border-radius); cursor: pointer;
            text-decoration: none; color: var(--purple-900);
        }
        .palette-item .pi-kind { font-size: .7rem; color: var(--purple-600); min-width: 3.4rem; text-transform: uppercase; }
        .palette-item .pi-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .palette-item.active, .palette-item:hover { background: var(--sidebar-active-bg, #7c3aed); color: var(--sidebar-active-color, #fff); }
        .palette-item.active .pi-kind { color: inherit; opacity: .85; }
        .palette-empty { padding: 1rem; text-align: center; color: var(--purple-700); }
        .palette-hint { padding: .4rem .7rem; font-size: .72rem; color: var(--purple-600); border-top: 1px solid var(--pink-300); }

        /* ---- Collapsible dashboard sections ---- */
        details.sys-card > summary {
            cursor: pointer; list-style: none; user-select: none; display: flex; align-items: center; gap: .35rem;
        }
        details.sys-card > summary::-webkit-details-marker { display: none; }
        details.sys-card > summary::before {
            content: '▸'; display: inline-block; transition: transform .15s ease; color: var(--purple-500);
        }
        details.sys-card[open] > summary::before { transform: rotate(90deg); }
        details.sys-card > summary h2 { margin: 0; }
        details.sys-card > summary .collapsible-meta { margin-left: auto; font-size: var(--font-size-sm); }
    </style>
</head>
<body class="admin-theme">
<?php $isSiteEditor = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/site-editor') !== false; ?>
<?php if (!empty($_SESSION['impersonator_id'])): ?>
    <div style="background:#7f1d1d;color:#fff;padding:.5rem 1rem;display:flex;gap:1rem;align-items:center;justify-content:center;border-radius:var(--border-radius);margin-bottom:var(--spacing-md);">
        <b>Impersonating — viewing the site as a member.</b>
        <form method="post" action="<?= url('/admin/impersonate/exit') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm" style="background:#fff;color:#7f1d1d;">Return to admin</button>
        </form>
    </div>
    <style>.impersonating .title-header { display: none; }</style>
<?php endif; ?>
    <header class="title-header"<?= $isSiteEditor ? ' style="display:none"' : '' ?>>
            <img src="<?= e(\App\Models\Theme::titleImageUrl(\App\Models\Theme::SCOPE_ADMIN)) ?>" alt="<?= e(config('app.site_name')) ?> Admin">
    </header>
    <div class="admin-shell">
        <div class="nav-backdrop" id="nav-backdrop" aria-hidden="true"></div>
        <nav class="admin-nav" id="admin-nav">
            <?php if (!empty($topContent)): ?>
                <div class="nav-top-content"><?= $topContent ?></div>
                <div class="nav-sep"></div>
            <?php endif; ?>
            <a class="nav-brand" href="<?= url('/admin') ?>">Admin</a>
            <form style="margin:0 0 var(--spacing-sm);" method="get" action="<?= url('/admin/search') ?>">
                <input type="search" name="q" placeholder="Search users, galleries…" value=""
                       style="width:100%;font-size:var(--font-size-sm);" aria-label="Global admin search">
            </form>
            <a class="nav-item <?= $navActive('/admin', true) ?>" href="<?= url('/admin') ?>">Dashboard</a>
            <a class="nav-item <?= $navActive('/admin/abandoned-uploads') ?>" href="<?= url('/admin/abandoned-uploads') ?>">Abandoned Uploads</a>
            <a class="nav-item <?= $navActive('/admin/trends') ?>" href="<?= url('/admin/trends') ?>">Trends</a>
            <a class="nav-item <?= $navActive('/admin/galleries') ?>" href="<?= url('/admin/galleries/create') ?>">Gallery Management</a>
            <a class="nav-item <?= $navActive('/admin/video-projects') ?>" href="<?= url('/admin/video-projects') ?>">Video Projects</a>
            <a class="nav-item <?= $navActive('/admin/auto-poster') ?>" href="<?= url('/admin/auto-poster') ?>">Auto Poster</a>
            <a class="nav-item <?= $navActive('/admin/categories') ?>" href="<?= url('/admin/categories') ?>">Categories</a>
            <a class="nav-item <?= $navActive('/admin/users') ?>" href="<?= url('/admin/users') ?>">Users</a>
            <a class="nav-item <?= $navActive('/admin/plans') ?>" href="<?= url('/admin/plans') ?>">Membership</a>
            <?php if (\App\Core\Auth::can('membership')): ?>
                <a class="nav-item <?= $navActive('/admin/subscriptions') ?>" href="<?= url('/admin/subscriptions') ?>">Subscriptions</a>
                <a class="nav-item <?= $navActive('/admin/payment-processors') ?>" href="<?= url('/admin/payment-processors') ?>">Payments</a>
            <?php endif; ?>
            <a class="nav-item <?= $navActive('/admin/theme') ?>" href="<?= url('/admin/theme') ?>">Theme</a>
            <?php if (\App\Core\Auth::can('site_editor')): ?>
                <a class="nav-item <?= $navActive('/admin/site-editor') ?>" href="<?= url('/admin/site-editor') ?>">Site Editor</a>
            <?php endif; ?>
            <a class="nav-item <?= $navActive('/admin/logs') ?>" href="<?= url('/admin/logs') ?>">Logs</a>
            <?php if (\App\Core\Auth::can('user_monitor')): ?>
                <a class="nav-item <?= $navActive('/admin/user-monitor') ?>" href="<?= url('/admin/user-monitor') ?>">User Monitor</a>
            <?php endif; ?>
            <a class="nav-item <?= $navActive('/admin/error-logs') ?>" href="<?= url('/admin/error-logs') ?>">Error Logs</a>
            <a class="nav-item <?= $navActive('/admin/system') ?>" href="<?= url('/admin/system') ?>">System</a>
            <a class="nav-item <?= $navActive('/admin/test-suite') ?>" href="<?= url('/admin/test-suite') ?>">Test suite</a>
            <a class="nav-item <?= $navActive('/admin/help') ?>" href="<?= url('/admin/help') ?>">Documentation</a>
            <?php if (\App\Core\Auth::can('support')): ?>
                <a class="nav-item <?= $navActive('/admin/support') ?>" href="<?= url('/admin/support') ?>">Support</a>
            <?php endif; ?>
            <div class="nav-sep"></div>
            <a class="nav-item" href="<?= url('/galleries') ?>">View Site</a>
            <a class="nav-item <?= $navActive('/settings') ?>" href="<?= url('/settings') ?>">Settings</a>
            <form class="nav-logout" method="post" action="<?= url('/logout') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
        </nav>
        <main class="admin-main">
            <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="admin-nav">☰</button>
            <div class="flash-stack" role="status" aria-live="polite">
            <?php foreach ($flash as $flashType => $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <div class="flash-toast <?= e($flashType) ?>">
                        <span><?= e($message) ?></span>
                        <button type="button" class="flash-close" aria-label="Dismiss notification">&times;</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </div>

            <?php require $content; ?>
        </main>
    </div>

    <div class="palette-overlay" id="admin-palette" hidden>
        <div class="palette" role="dialog" aria-modal="true" aria-label="Quick navigation">
            <input type="search" id="palette-input" class="palette-input" placeholder="Search galleries, users, plans, pages…  (Esc to close)" autocomplete="off">
            <div class="palette-list" id="palette-results"></div>
            <div class="palette-hint">Ctrl+K or / to open · ↑↓ navigate · Enter open · Esc close</div>
        </div>
    </div>

    <div class="progress-overlay" id="admin-progress" aria-live="polite">
        <div class="progress-box">
            <div class="pb-spinner"></div>
            <div class="pb-label" id="ap-label">Working…</div>
            <div class="pb-sub" id="ap-sub"></div>
            <div class="progress-track"><div class="progress-fill" id="ap-fill"></div></div>
            <div class="pb-percent" id="ap-percent"></div>
        </div>
    </div>

    <script>
    (function () {
        var overlay = document.getElementById('admin-progress');
        var label = document.getElementById('ap-label');
        var sub = document.getElementById('ap-sub');
        var fill = document.getElementById('ap-fill');
        var percentEl = document.getElementById('ap-percent');
        var timer = null;

        // Global progress API usable from any admin page:
        //   AdminProgress.show('Uploading…')
        //   AdminProgress.progress(45, 'photo 3 of 8')
        //   AdminProgress.hide()
        window.AdminProgress = {
            show: function (text) {
                label.textContent = text || 'Working…';
                sub.textContent = '';
                fill.style.width = '0%';
                percentEl.textContent = '';
                overlay.classList.add('active');
                return this;
            },
            progress: function (percent, msg) {
                var p = Math.max(0, Math.min(100, Math.round(percent)));
                fill.style.width = p + '%';
                percentEl.textContent = p + '%';
                if (msg) sub.textContent = msg;
                return this;
            },
            hide: function () {
                overlay.classList.remove('active');
                if (timer) { clearInterval(timer); timer = null; }
                return this;
            },
            busy: function (text) {
                this.show(text);
                if (timer) clearInterval(timer);
                // Indeterminate pulse while there is no numeric progress.
                timer = setInterval(function () {
                    var cur = parseInt(fill.style.width) || 0;
                    var next = cur >= 92 ? 8 : Math.min(92, cur + 8 + Math.round(Math.random() * 20));
                    fill.style.width = next + '%';
                }, 400);
                return this;
            },
            settle: function () {
                fill.style.width = '100%';
                if (timer) { clearInterval(timer); timer = null; }
            }
        };

        // Automatically show the overlay whenever any admin form is submitted
        // (covers saves on all admin pages). The page navigates on success so
        // the overlay clears itself on load; forms that stay (e.g. inline
        // controls) call AdminProgress.hide() via the global progress event.
        // Forms with an inline onsubmit handler (e.g. return confirm(...)) run
        // that handler *after* this capture listener; if the user cancels, the
        // submit is prevented and the optimistic overlay would stay stuck, so
        // skip those forms and let them manage progress themselves.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches('form')) return;
            if (form.getAttribute('data-no-progress') !== null) return;
            if (form.hasAttribute('onsubmit')) return;
            window.AdminProgress.busy('Saving…');
        }, true);

        // Safety net: never leave a full-screen busy overlay stuck over the
        // admin UI. Pressing Escape or clicking the backdrop dismisses it.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.AdminProgress.hide();
        });
        overlay.addEventListener('click', function () {
            window.AdminProgress.hide();
        });

        // Allow any fetch-based operation to surface progress generically:
        //   document.dispatchEvent(new CustomEvent('admin-progress', {
        //       detail: { show: true, label: 'Uploading…', value: 42, sub: '2 of 5' } }));
        document.addEventListener('admin-progress', function (e) {
            var d = (e && e.detail) || {};
            if (d.show) {
                if (d.indeterminate) window.AdminProgress.busy(d.label || 'Working…');
                else window.AdminProgress.show(d.label || 'Working…');
                if (typeof d.value === 'number') window.AdminProgress.progress(d.value, d.sub || '');
            } else if (d.settle) {
                window.AdminProgress.settle();
            } else {
                window.AdminProgress.hide();
            }
        });
    })();
    </script>
<?php
$_activeAdminTpl = \App\Models\SiteTemplate::active(\App\Models\SiteTemplate::SCOPE_ADMIN);
if ($_activeAdminTpl !== null && empty($_GET['se'])):
$_tplChanges = json_decode((string) $_activeAdminTpl['config_json'], true) ?: [];
$_tplJson = json_encode($_tplChanges, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
    <script>
    (function () {
        var changes = <?= $_tplJson ?>;
        if (!Array.isArray(changes)) changes = [];
        function findItem(item) {
            var el = item.origin ? document.querySelector(item.origin) : null;
            if (!el && item.key) el = document.querySelector('[data-se-move-key="' + item.key + '"]');
            if (el && item.key) el.setAttribute('data-se-move-key', item.key);
            if (el && item.styles) Object.keys(item.styles).forEach(function (key) { if (item.styles[key]) el.style.setProperty(key, item.styles[key]); });
            return el;
        }
        function applyOrder(change) {
            var parent = change.parentKey === 'body' ? document.body : null;
            if (change.parentOrigin) { parent = document.querySelector(change.parentOrigin) || parent; if (parent && change.parentKey) parent.setAttribute('data-se-move-key', change.parentKey); }
            if (!parent) return;
            (change.items || []).map(findItem).filter(Boolean).forEach(function (item) {
                parent.appendChild(item);
            });
        }
        changes.forEach(function (change) {
            try {
                if (change.type === 'order') { applyOrder(change); return; }
                var el = change.key ? document.querySelector('[data-se-move-key="' + change.key + '"]') : null;
                if (!el && change.origin) { el = document.querySelector(change.origin); if (el && change.key) el.setAttribute('data-se-move-key', change.key); }
                if (!el) el = document.querySelector(change.selector);
                if (!el) return;
                if (change.type === 'hide' || change.type === 'delete') el.style.display = 'none';
                else if (change.type === 'restyle') Object.keys(change.styles || {}).forEach(function (key) { el.style[key] = change.styles[key]; });
                else if (change.type === 'move') {
                    if (change.anchor || change.parent) {
                        var anchor = change.anchorKey ? document.querySelector('[data-se-move-key="' + change.anchorKey + '"]') : null;
                        if (!anchor && change.anchorOrigin) { anchor = document.querySelector(change.anchorOrigin); if (anchor && change.anchorKey) anchor.setAttribute('data-se-move-key', change.anchorKey); }
                        if (!anchor && change.anchor) anchor = document.querySelector(change.anchor);
                        if (anchor && anchor !== el && !el.contains(anchor)) {
                            if (change.position === 'before') anchor.parentNode.insertBefore(el, anchor);
                            else anchor.parentNode.insertBefore(el, anchor.nextSibling);
                        } else {
                            var parent = change.parent === 'body' ? document.body : (change.parent ? document.querySelector(change.parent) : null);
                            if (parent && change.position === 'append') parent.appendChild(el);
                        }
                    } else {
                        var vw = document.documentElement.clientWidth || 1;
                        var vh = document.documentElement.clientHeight || 1;
                        var rect = el.getBoundingClientRect();
                        var mx = change.targetXRatio != null ? change.targetXRatio * vw - rect.left : (change.dxRatio != null ? change.dxRatio * vw : (change.dx || 0));
                        var my = change.targetYRatio != null ? change.targetYRatio * vh - rect.top : (change.dyRatio != null ? change.dyRatio * vh : (change.dy || 0));
                        el.style.setProperty('transform', 'translate(' + mx + 'px,' + my + 'px)', 'important');
                    }
                } else if (change.type === 'add') {
                    var target = change.parent ? document.querySelector(change.parent) : document.body;
                    if (!target) return;
                    var added = document.createElement(change.tag || 'div');
                    added.className = 'se-added-element';
                    added.setAttribute('data-se-added', '1');
                    added.innerHTML = change.html || '';
                    Object.keys(change.styles || {}).forEach(function (key) { added.style[key] = change.styles[key]; });
                    if (change.position === 'prepend') target.prepend(added);
                    else if (change.position === 'before' && target.parentElement) target.parentElement.insertBefore(added, target);
                    else if (change.position === 'after' && target.parentElement) target.parentElement.insertBefore(added, target.nextSibling);
                    else target.appendChild(added);
                }
            } catch (ignore) {}
        });
    })();
    </script>
<?php endif; ?>

    <script>
        // Admin shell behaviours: mobile nav drawer, auto-dismissing flash
        // toasts, the Ctrl+K command palette, and remembered collapsible
        // dashboard sections.
        (function () {
            'use strict';
            var base = <?= json_encode(rtrim((string) url('/'), '/')) ?>;

            // --- Mobile nav drawer ---
            var navToggle = document.getElementById('nav-toggle');
            var navBackdrop = document.getElementById('nav-backdrop');
            function setNav(open) {
                document.body.classList.toggle('nav-open', open);
                if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (navToggle) {
                navToggle.addEventListener('click', function () {
                    setNav(!document.body.classList.contains('nav-open'));
                });
            }
            if (navBackdrop) {
                navBackdrop.addEventListener('click', function () { setNav(false); });
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') setNav(false);
            });

            // --- Flash toasts ---
            document.querySelectorAll('.flash-toast').forEach(function (toast) {
                var close = toast.querySelector('.flash-close');
                if (close) {
                    close.addEventListener('click', function () { dismiss(toast); });
                }
                setTimeout(function () { dismiss(toast); }, 4500);
            });
            function dismiss(toast) {
                if (toast.classList.contains('hide')) return;
                toast.classList.add('hide');
                setTimeout(function () { toast.remove(); }, 320);
            }

            // --- Command palette (Ctrl/Cmd+K or "/") ---
            var palette = document.getElementById('admin-palette');
            var paletteInput = document.getElementById('palette-input');
            var paletteResults = document.getElementById('palette-results');
            var paletteTimer = null;
            var paletteItems = [];
            var paletteIndex = -1;

            function openPalette() {
                if (!palette) return;
                palette.hidden = false;
                setTimeout(function () {
                    if (paletteInput) paletteInput.focus();
                }, 10);
            }
            function closePalette() {
                if (palette) palette.hidden = true;
                paletteResults.innerHTML = '';
                paletteItems = [];
                paletteIndex = -1;
            }
            function isTypingTarget(e) {
                var t = e.target;
                if (!t || !t.tagName) return false;
                var name = t.tagName.toLowerCase();
                return name === 'input' || name === 'textarea' || t.isContentEditable;
            }
            document.addEventListener('keydown', function (e) {
                var k = e.key.toLowerCase();

                // Ctrl/Cmd+K toggles the palette.
                if ((e.ctrlKey || e.metaKey) && k === 'k') {
                    e.preventDefault();
                    if (palette && palette.hidden) openPalette();
                    else closePalette();
                    return;
                }

                // "/" opens the palette unless the user is typing somewhere.
                if (k === '/' && !isTypingTarget(e)) {
                    e.preventDefault();
                    openPalette();
                    return;
                }

                // Escape closes whichever layer is open: the palette first,
                // then the mobile nav drawer. Works regardless of focus.
                if (e.key === 'Escape') {
                    if (palette && !palette.hidden) {
                        e.preventDefault();
                        closePalette();
                    } else {
                        setNav(false);
                    }
                }
            });
            if (palette) {
                palette.addEventListener('click', function (e) {
                    if (e.target === palette) closePalette();
                });
            }
            if (paletteInput) {
                paletteInput.addEventListener('input', function () {
                    clearTimeout(paletteTimer);
                    var q = paletteInput.value.trim();
                    if (q.length < 1) {
                        paletteResults.innerHTML = '';
                        paletteItems = [];
                        paletteIndex = -1;
                        return;
                    }
                    paletteTimer = setTimeout(function () { searchPalette(q); }, 220);
                });
                paletteInput.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); moveIndex(1); return; }
                    if (e.key === 'ArrowUp') { e.preventDefault(); moveIndex(-1); return; }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (paletteIndex >= 0 && paletteItems[paletteIndex]) {
                            window.location.href = paletteItems[paletteIndex].href;
                        }
                    }
                });
            }

            function moveIndex(delta) {
                if (!paletteItems.length) return;
                paletteIndex = (paletteIndex + delta + paletteItems.length) % paletteItems.length;
                renderPaletteHighlight();
            }
            function renderPaletteHighlight() {
                var nodes = paletteResults.querySelectorAll('.palette-item');
                nodes.forEach(function (node, i) {
                    node.classList.toggle('active', i === paletteIndex);
                });
                var active = nodes[paletteIndex];
                if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
            }
            function searchPalette(q) {
                fetch(base + '/admin/search.json?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'fetch' },
                    credentials: 'same-origin'
                }).then(function (res) {
                    return res.json();
                }).then(function (data) {
                    if (!data || !data.groups) return;
                    paletteItems = [];
                    paletteIndex = -1;
                    var html = '';
                    var empty = true;
                    data.groups.forEach(function (group) {
                        if (!group.items || !group.items.length) return;
                        empty = false;
                        html += '<div class="palette-group">' + escapeHtml(group.label) + '</div>';
                        group.items.forEach(function (item) {
                            paletteItems.push({ href: item.href });
                            html += '<a class="palette-item" href="' + escapeAttr(item.href) + '">'
                                + '<span class="pi-kind">' + escapeHtml(item.kind) + '</span>'
                                + '<span class="pi-title">' + escapeHtml(item.title) + '</span></a>';
                        });
                    });
                    if (empty) {
                        html = '<div class="palette-empty">No matches for “' + escapeHtml(q) + '”.</div>';
                    }
                    paletteResults.innerHTML = html;
                    paletteResults.querySelectorAll('.palette-item').forEach(function (node, i) {
                        node.addEventListener('click', function () { paletteIndex = i; });
                    });
                }).catch(function () {
                    paletteResults.innerHTML = '<div class="palette-empty">Search is unavailable right now.</div>';
                });
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            function escapeAttr(s) {
                return String(s).replace(/["&]/g, function (c) {
                    return { '&': '&amp;', '"': '&quot;' }[c];
                });
            }

            // --- Remembered collapsible sections ---
            document.querySelectorAll('details.sys-card[data-collapse-key]').forEach(function (details) {
                var key = 'admin-collapse-' + details.getAttribute('data-collapse-key');
                try {
                    var saved = localStorage.getItem(key);
                    if (saved === 'closed') details.removeAttribute('open');
                } catch (ignore) {}
                details.addEventListener('toggle', function () {
                    try {
                        localStorage.setItem(key, details.hasAttribute('open') ? 'open' : 'closed');
                    } catch (ignore) {}
                });
            });
        })();
    </script>

    <script>
        (function () {
            'use strict';
            var wakeLock = null;
            var wanted = false;

            function supported() {
                return 'wakeLock' in navigator;
            }

            function requestLock() {
                if (!supported() || !wanted || wakeLock) return;
                navigator.wakeLock.request('screen').then(function (lock) {
                    wakeLock = lock;
                    lock.addEventListener('release', function () {
                        wakeLock = null;
                        if (wanted) requestLock();
                    });
                }).catch(function () {
                    wakeLock = null;
                });
            }

            function releaseLock() {
                wanted = false;
                if (wakeLock) {
                    var lock = wakeLock;
                    wakeLock = null;
                    lock.release().catch(function () {});
                }
            }

            function hasFiles(form) {
                var inputs = form.querySelectorAll('input[type="file"]');
                for (var i = 0; i < inputs.length; i++) {
                    if (inputs[i].files && inputs[i].files.length > 0) return true;
                }
                return false;
            }

            document.addEventListener('submit', function (e) {
                if (e.target && e.target.tagName === 'FORM' && hasFiles(e.target)) {
                    wanted = true;
                    requestLock();
                }
            }, true);

            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible') requestLock();
            });

            window.addEventListener('pagehide', releaseLock);
        })();
    </script>
</body>
</html>
