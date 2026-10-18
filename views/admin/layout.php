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

// The sidebar as data. Rendering the nav and the breadcrumb from one list keeps
// them in step, and keeps the flat set of site-editor move keys in one place.
// `perm` is an Auth::can() capability; `exact` marks the dashboard so /admin
// does not stay lit while you are on /admin/users.
$navGroups = [
    [
        'key'   => 'nav-group-overview',
        'label' => 'Overview',
        'items' => [
            ['key' => 'nav-dashboard', 'label' => 'Dashboard', 'href' => '/admin', 'icon' => 'grid', 'exact' => true],
            ['key' => 'nav-abandoned-uploads', 'label' => 'Abandoned Uploads', 'href' => '/admin/abandoned-uploads', 'icon' => 'upload'],
            ['key' => 'nav-trends', 'label' => 'Trends', 'href' => '/admin/trends', 'icon' => 'trend'],
            ['key' => 'nav-traffic', 'label' => 'Traffic', 'href' => '/admin/traffic', 'icon' => 'bars', 'perm' => 'traffic'],
            ['key' => 'nav-analytics', 'label' => 'Web Analytics', 'href' => '/admin/analytics', 'icon' => 'pie', 'perm' => 'analytics'],
        ],
    ],
    [
        'key'   => 'nav-group-content',
        'label' => 'Content',
        'items' => [
            ['key' => 'nav-galleries', 'label' => 'Gallery Management', 'href' => '/admin/galleries', 'icon' => 'image'],
            ['key' => 'nav-categories', 'label' => 'Categories', 'href' => '/admin/categories', 'icon' => 'tag'],
            ['key' => 'nav-category-suggestions', 'label' => 'AI Suggestions', 'href' => '/admin/category-suggestions', 'icon' => 'sparkle'],
            ['key' => 'nav-description-suggestions', 'label' => 'AI Descriptions', 'href' => '/admin/description-suggestions', 'icon' => 'sparkle'],
            ['key' => 'nav-video-projects', 'label' => 'Video Projects', 'href' => '/admin/video-projects', 'icon' => 'film'],
            ['key' => 'nav-profile', 'label' => 'Creator Profile', 'href' => '/admin/profile', 'icon' => 'user'],
            ['key' => 'nav-theme', 'label' => 'Theme', 'href' => '/admin/theme', 'icon' => 'palette'],
            ['key' => 'nav-site-editor', 'label' => 'Site Editor', 'href' => '/admin/site-editor', 'icon' => 'layout', 'perm' => 'site_editor'],
        ],
    ],
    [
        'key'   => 'nav-group-community',
        'label' => 'Community & growth',
        'items' => [
            ['key' => 'nav-engagement', 'label' => 'Engagement', 'href' => '/admin/engagement', 'icon' => 'heart'],
            ['key' => 'nav-users', 'label' => 'Users', 'href' => '/admin/users', 'icon' => 'users'],
            ['key' => 'nav-chat', 'label' => 'Chat', 'href' => '/admin/chat', 'icon' => 'chat', 'perm' => 'chat'],
            ['key' => 'nav-support', 'label' => 'Support', 'href' => '/admin/support', 'icon' => 'lifebuoy', 'perm' => 'support'],
            ['key' => 'nav-auto-poster', 'label' => 'Auto Poster', 'href' => '/admin/auto-poster', 'icon' => 'send'],
        ],
    ],
    [
        'key'   => 'nav-group-money',
        'label' => 'Monetization',
        'items' => [
            ['key' => 'nav-earnings', 'label' => 'Earnings', 'href' => '/admin/earnings', 'icon' => 'dollar'],
            ['key' => 'nav-plans', 'label' => 'Membership Plans', 'href' => '/admin/plans', 'icon' => 'card', 'perm' => 'membership'],
            ['key' => 'nav-subscriptions', 'label' => 'Subscriptions', 'href' => '/admin/subscriptions', 'icon' => 'repeat', 'perm' => 'membership'],
            ['key' => 'nav-payments', 'label' => 'Payment Processors', 'href' => '/admin/payment-processors', 'icon' => 'bank', 'perm' => 'membership'],
            ['key' => 'nav-emailer', 'label' => 'Emailer', 'href' => '/admin/emailer', 'icon' => 'mail', 'perm' => 'membership'],
        ],
    ],
    [
        'key'   => 'nav-group-system',
        'label' => 'System',
        'items' => [
            ['key' => 'nav-system', 'label' => 'System', 'href' => '/admin/system', 'icon' => 'server'],
            ['key' => 'nav-mail', 'label' => 'Email', 'href' => '/admin/mail', 'icon' => 'inbox'],
            ['key' => 'nav-logs', 'label' => 'Logs', 'href' => '/admin/logs', 'icon' => 'doc'],
            ['key' => 'nav-error-logs', 'label' => 'Error Logs', 'href' => '/admin/error-logs', 'icon' => 'alert'],
            ['key' => 'nav-user-monitor', 'label' => 'User Monitor', 'href' => '/admin/user-monitor', 'icon' => 'activity', 'perm' => 'user_monitor'],
            ['key' => 'nav-test-suite', 'label' => 'Test suite', 'href' => '/admin/test-suite', 'icon' => 'check'],
            ['key' => 'nav-help', 'label' => 'Documentation', 'href' => '/admin/help', 'icon' => 'book'],
        ],
    ],
];

// Drop anything the signed-in admin may not see, then work out where we are so
// the breadcrumb can show "Admin / <group> / <page>".
$navGroups = array_values(array_filter(array_map(static function (array $group): array {
    $group['items'] = array_values(array_filter($group['items'], static function (array $item): bool {
        return !isset($item['perm']) || \App\Core\Auth::can($item['perm']);
    }));

    return $group;
}, $navGroups), static function (array $group): bool {
    return $group['items'] !== [];
}));

$crumbGroup = null;
$crumbGroupHref = null;
foreach ($navGroups as $group) {
    foreach ($group['items'] as $item) {
        if ($navActive($item['href'], !empty($item['exact'])) === 'active') {
            $crumbGroup = $group['label'];
            $crumbGroupHref = $item['href'];
            break 2;
        }
    }
    if ($crumbGroupHref === null && $group['items'] !== []) {
        $crumbGroupHref = $group['items'][0]['href'];
    }
}

$pageTitle = isset($title) ? (string) $title : 'Dashboard';

// Sidebar identity. Long emails are the norm here, so the label is clamped to
// one line and the full address stays in the title attribute.
$adminUser = \App\Core\Auth::user();
$adminEmail = (string) ($adminUser['email'] ?? '');
$adminRole = (string) ($adminUser['role'] ?? 'admin');
$adminRoleLabel = ['super_admin' => 'Super admin', 'admin' => 'Administrator', 'moderator' => 'Moderator'][$adminRole] ?? ucfirst(str_replace('_', ' ', $adminRole));
$adminInitial = $adminEmail !== '' ? strtoupper(substr($adminEmail, 0, 1)) : '?';
$icon = static fn (string $name): string => \App\Core\Icon::svg($name);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($title) ? e($title) . ' — ' . config('app.site_name') . ' Admin' : e(config('app.site_name')) . ' Admin' ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/admin-shared.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/user.css')) ?>">
    <style>
    <?= \App\Models\Theme::css(\App\Models\Theme::SCOPE_ADMIN) ?>
    <?= \App\Models\Theme::cssLayout(\App\Models\Theme::SCOPE_ADMIN) ?>
        body.admin-theme { font-size: var(--font-size-base); line-height: var(--line-height); }
        .admin-theme a { color: var(--adm-accent-strong); }
    </style>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/admin.css')) ?>">
</head>
<body class="admin-theme site-compact" data-base="<?= e(config('app.base_path')) ?>" data-user="1">
<a class="skip-link" href="#admin-content">Skip to main content</a>
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
    <div class="admin-shell" data-se-move-key="shell">
        <div class="nav-backdrop" id="nav-backdrop" aria-hidden="true" data-se-move-key="nav-backdrop"></div>
        <nav class="admin-nav" id="admin-nav" aria-label="Admin sections" data-se-move-key="nav">
            <?php if (!empty($topContent)): ?>
                <div class="nav-top-content" data-se-move-key="nav-top-content"><?= $topContent ?></div>
                <div class="nav-sep" data-se-move-key="nav-sep-top"></div>
            <?php endif; ?>
            <a class="nav-brand" href="<?= url('/admin') ?>" data-se-move-key="nav-brand">
                <img src="<?= e(\App\Models\Theme::titleImageUrl(\App\Models\Theme::SCOPE_ADMIN)) ?>" alt="<?= e(config('app.site_name')) ?> Admin" onerror="this.remove()">
            </a>
            <div class="nav-scroll">
            <?php foreach ($navGroups as $group): ?>
                <div class="nav-group" data-se-move-key="<?= e($group['key']) ?>"><?= e($group['label']) ?></div>
                <?php foreach ($group['items'] as $item): ?>
                    <?php $isActive = $navActive($item['href'], !empty($item['exact'])) === 'active'; ?>
                    <a class="nav-item <?= $isActive ? 'active' : '' ?>" href="<?= e(url($item['href'])) ?>"
                       data-se-move-key="<?= e($item['key']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                        <?= $icon($item['icon']) ?><span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
                <div class="nav-sep" data-se-move-key="nav-sep"></div>
                <a class="nav-item" href="<?= e(url('/galleries')) ?>" data-se-move-key="nav-view-site"><?= $icon('external') ?><span>View Site</span></a>
                <a class="nav-item <?= $navActive('/settings') ?>" href="<?= e(url('/settings')) ?>" data-se-move-key="nav-settings"><?= $icon('sliders') ?><span>Settings</span></a>
            </div>
            <div class="nav-foot" data-se-move-key="nav-foot">
                <div class="nav-user" title="<?= e($adminEmail) ?>">
                    <span class="nav-avatar" aria-hidden="true"><?= e($adminInitial) ?></span>
                    <span class="nav-user-text">
                        <strong><?= e($adminEmail) ?></strong>
                        <small><?= e($adminRoleLabel) ?></small>
                    </span>
                </div>
                <form class="nav-logout" method="post" action="<?= e(url('/logout')) ?>" data-se-move-key="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="nav-logout-btn" aria-label="Sign out" title="Sign out"><?= $icon('logout') ?></button>
                </form>
            </div>
        </nav>
        <main class="admin-main" id="admin-content" tabindex="-1" data-se-move-key="main">
            <div class="admin-topbar">
                <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="admin-nav"><?= $icon('menu') ?></button>
                <div class="topbar-lead">
                    <?php if ($crumbGroup !== null || $pageTitle !== 'Dashboard'): ?>
                        <nav class="adm-crumb" aria-label="Breadcrumb">
                            <a href="<?= e(url('/admin')) ?>">Admin</a>
                            <?php if ($crumbGroup !== null): ?>
                                <?= $icon('chevron', 'crumb-sep') ?>
                                <?php if ($current === $base . $crumbGroupHref): ?>
                                    <span><?= e($crumbGroup) ?></span>
                                <?php else: ?>
                                    <a href="<?= e(url($crumbGroupHref)) ?>"><?= e($crumbGroup) ?></a>
                                    <?= $icon('chevron', 'crumb-sep') ?>
                                    <span aria-current="page"><?= e($pageTitle) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= $icon('chevron', 'crumb-sep') ?>
                                <span aria-current="page"><?= e($pageTitle) ?></span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                    <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
                </div>
                <span class="topbar-spacer"></span>
                <form class="admin-top-search" method="get" action="<?= e(url('/admin/search')) ?>" data-se-move-key="nav-search">
                    <span class="top-search-ico" aria-hidden="true"><?= $icon('search', 'nav-ico') ?></span>
                    <input type="search" name="q" placeholder="Search users, galleries&hellip;" value="" aria-label="Global admin search">
                    <kbd class="top-search-kbd" aria-hidden="true">Ctrl K</kbd>
                </form>
                <a class="topbar-link" href="<?= e(url('/galleries')) ?>"><?= $icon('external') ?><span>View site</span></a>
            </div>
            <div class="flash-stack" role="status" aria-live="polite">
            <?php foreach ($flash as $flashType => $flashMessages): ?>
                <?php foreach ($flashMessages as $flashMessage): ?>
                    <div class="flash-toast <?= e($flashType) ?>">
                        <span><?= e($flashMessage) ?></span>
                        <button type="button" class="flash-close" aria-label="Dismiss notification">&times;</button>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </div>

            <?php echo $content; ?>
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

    <script nonce="<?= csp_nonce() ?>">
    // Admin chrome: data-* driven behaviours (strict CSP, no inline handlers).
    // Mirrors views/layout.php conventions so admin pages get the same
    // delegation: data-confirm, data-action, data-copy, data-autosubmit,
    // data-picker, data-stop-prop. Registered before the progress handler so a
    // cancelled confirm stops the submit before the busy overlay is shown.
    (function () {
        'use strict';
        window.__copyText = function (text) {
            text = String(text);
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function (resolve, reject) {
                var ta = document.createElement('textarea');
                ta.value = text; ta.setAttribute('readonly', '');
                ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
                document.body.appendChild(ta);
                var ok = false;
                try { ta.focus(); ta.select(); ta.setSelectionRange(0, text.length); ok = document.execCommand('copy'); } catch (e) {}
                ta.remove();
                ok ? resolve() : reject(new Error('copy failed'));
            });
        };
        window.__copied = function (el, ok) {
            if (!el) return;
            if (el.getAttribute('data-copy-label') === null) el.setAttribute('data-copy-label', el.textContent);
            el.textContent = ok ? 'Copied' : 'Copy failed';
            clearTimeout(el.__copyTimer);
            el.__copyTimer = setTimeout(function () { el.textContent = el.getAttribute('data-copy-label'); }, 1500);
        };
        window.copyToClipboard = function (sel, el) {
            var node = (sel && sel.nodeName && sel.nodeType) ? sel : document.getElementById(String(sel));
            if (!node) return;
            var text = ('value' in node) ? node.value : node.textContent;
            window.__copyText(text).then(function () { window.__copied(el, true); }, function () { window.__copied(el, false); });
        };
        window.copyLinkValue = function (value, el) {
            window.__copyText(value).then(function () { window.__copied(el, true); }, function () { window.__copied(el, false); });
        };
        window.showPickerOn = function (el) { if (el) { try { el.showPicker && el.showPicker(); } catch (e) {} } };

        document.addEventListener('submit', function (e) {
            var t = e.target, m;
            if (t && t.tagName === 'FORM' && (m = t.getAttribute('data-confirm')) && !window.confirm(m)) {
                e.preventDefault(); e.stopImmediatePropagation();
            }
        }, true);
        document.addEventListener('change', function (e) {
            var t = e.target;
            if (!t || !t.hasAttribute) return;
            if (t.hasAttribute('data-autosubmit') && t.form) t.form.submit();
            if (t.hasAttribute('data-action')) { var a = t.getAttribute('data-action'); if (window[a]) window[a](t, e); }
        }, true);
        document.addEventListener('click', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('[data-confirm],[data-action],[data-copy],[data-picker],[data-stop-prop]') : null;
            if (!t) return;
            if (t.hasAttribute('data-stop-prop')) { e.stopPropagation(); return; }
            var m = t.getAttribute('data-confirm');
            if (m && t.tagName !== 'FORM' && !window.confirm(m)) { e.preventDefault(); e.stopImmediatePropagation(); return; }
            var a = t.getAttribute('data-action');
            if (a && window[a]) { if (t.hasAttribute('data-arg')) window[a](t.getAttribute('data-arg'), t); else window[a](t, e); e.preventDefault(); }
            if (t.hasAttribute('data-copy')) { window.copyToClipboard(t.getAttribute('data-copy'), t); e.preventDefault(); }
            if (t.hasAttribute('data-picker')) { window.showPickerOn(t); e.preventDefault(); }
        }, true);
    })();
    </script>
    <script nonce="<?= csp_nonce() ?>">
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

        // --- Keep the admin's scroll position when a button submits a form
        // or a dashboard period selector is clicked and the page reloads.
        // The position is saved per URL so a same-page reload (toggle,
        // delete, approve, save, period change) lands back where the admin
        // was instead of snapping to the top.
        var scrollKey = 'admin-scroll-pos';
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        try {
            var adminScroll = sessionStorage.getItem(scrollKey);
            if (adminScroll !== null) {
                sessionStorage.removeItem(scrollKey);
                var savedPos = JSON.parse(adminScroll);
                if (savedPos && savedPos.p === location.pathname + location.search && !location.hash) {
                    setTimeout(function () {
                        window.scrollTo(0, parseInt(savedPos.y, 10) || 0);
                    }, 0);
                }
            }
        } catch (ignore) {}
        function rememberScrollFor(targetUrl) {
            try {
                var url = new URL(targetUrl, location.href);
                sessionStorage.setItem(scrollKey, JSON.stringify({
                    p: url.pathname + url.search,
                    y: window.scrollY || 0
                }));
            } catch (ignore) {}
        }
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.matches('form')) return;
            if (form.getAttribute('data-no-scroll-restore') !== null) return;
            rememberScrollFor(location.href);
        }, true);
        document.addEventListener('click', function (e) {
            var link = e.target && e.target.closest ? e.target.closest('a[data-collapse-preserve]') : null;
            if (link) rememberScrollFor(link.href);
        }, true);
    })();
    </script>
<?php
$_activeAdminTpl = \App\Models\SiteTemplate::active(\App\Models\SiteTemplate::SCOPE_ADMIN);
if ($_activeAdminTpl !== null && empty($_GET['se'])):
$_tplChanges = json_decode((string) $_activeAdminTpl['config_json'], true) ?: [];
$_tplJson = json_encode($_tplChanges, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
    <script nonce="<?= csp_nonce() ?>">
    (function () {
        var changes = <?= $_tplJson ?>;
        if (!Array.isArray(changes)) changes = [];
        function findItem(item) {
            // Prefer the stable move-key over the CSS origin selector. The
            // origin selector is positional (e.g. "form:nth-of-type(1)") and
            // silently resolves to a DIFFERENT element when the page structure
            // changes (e.g. a sidebar form is added/removed), which previously
            // moved the logout button to the top of the admin nav.
            var el = item.key ? document.querySelector('[data-se-move-key="' + item.key + '"]') : null;
            if (!el && item.origin) el = document.querySelector(item.origin);
            // Never clobber a stable key that was baked into the HTML: if the
            // element already carries one, keep it so stored-template keys
            // (m1, m2, ...) can never fight over the same element.
            if (el && item.key && !el.hasAttribute('data-se-move-key')) el.setAttribute('data-se-move-key', item.key);
            if (el && item.styles) Object.keys(item.styles).forEach(function (key) { if (item.styles[key]) el.style.setProperty(key, item.styles[key]); });
            return el;
        }
        function applyOrder(change) {
            var parent = change.parentKey === 'body' ? document.body : null;
            if (change.parentOrigin) { parent = document.querySelector(change.parentOrigin) || parent; if (parent && change.parentKey) parent.setAttribute('data-se-move-key', change.parentKey); }
            if (!parent) return;
            var items = (change.items || []).map(findItem).filter(Boolean);
            // Only honour a stored order when it is a complete, current view of the
            // parent's stable-keyed children. A saved order captured against an
            // older layout (a menu item added/removed/renamed since it was saved)
            // has missing keys and leaves those leftovers in their original slots,
            // which scrambles the admin nav on every load. In that case fall back
            // to the canonical HTML order instead of reordering.
            if (parent !== document.body) {
                var current = {}, currentCount = 0, seen = {}, missing = false;
                for (var n = parent.firstChild; n; n = n.nextSibling) {
                    if (n.nodeType === 1 && n.hasAttribute('data-se-move-key')) {
                        current[n.getAttribute('data-se-move-key')] = true;
                        currentCount += 1;
                    }
                }
                items.forEach(function (el) {
                    var k = el.getAttribute('data-se-move-key');
                    if (!k) return;
                    if (!current[k]) missing = true;
                    seen[k] = true;
                });
                if (missing || Object.keys(seen).length !== currentCount) return;
            }
            items.forEach(function (item) { parent.appendChild(item); });
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

    <script nonce="<?= csp_nonce() ?>">
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
            // aria-expanded is kept in step with the real open state so assistive
            // tech announces the panel correctly; browsers do not do this for
            // <details> on their own.
            document.querySelectorAll('details[data-collapse-key]').forEach(function (details) {
                var key = 'admin-collapse-' + details.getAttribute('data-collapse-key');
                try {
                    var saved = localStorage.getItem(key);
                    if (saved === 'closed') details.removeAttribute('open');
                } catch (ignore) {}
                var sync = function () {
                    var summary = details.querySelector(':scope > summary');
                    if (summary) summary.setAttribute('aria-expanded', details.hasAttribute('open') ? 'true' : 'false');
                };
                sync();
                details.addEventListener('toggle', function () {
                    sync();
                    try {
                        localStorage.setItem(key, details.hasAttribute('open') ? 'open' : 'closed');
                    } catch (ignore) {}
                });
            });
        })();
    </script>

    <script nonce="<?= csp_nonce() ?>">
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
    <?php require __DIR__ . '/../partials/tour_targets.php'; ?>
    <script nonce="<?= csp_nonce() ?>" src="<?= e(url('/assets/js/tour.js')) ?>?v=9" defer></script>
</body>
</html>
