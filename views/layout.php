<?php
// Shared site chrome: header image, top navigation, flash messages. Pages
// render their own markup into $content. For logged-in users the layout
// wraps content in a left sidebar (favourite categories + Settings/Logout)
// so the nav is visible on every page; the top nav then hides its own
// Settings/Logout buttons to avoid duplicates.
$user = \App\Core\Auth::user();
$flash = \App\Core\Flash::all();
\App\Core\Flash::clear();
$sidebarNav = $sidebarNav ?? false;
$userThemePreset = null;
if ($user !== null && \App\Core\Auth::canUseCustomTheme()) {
    $userThemePreset = $user['theme_preset'] ?? null;
}

// Carry the current images/videos filter onto sidebar and section links so
// switching pages never silently drops a selected filter.
$currentType = in_array($_GET['type'] ?? '', ['images', 'videos'], true)
    ? (string) $_GET['type']
    : '';
$typeSuffix  = $currentType !== '' ? '?type=' . $currentType : '';

// Used to hide the nav "Login" button while the user is already on the
// login page (a redundant click).
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
$isLoginPage = $currentPath === url('/login');

// The login, signup and (for guests) membership pages use a focused panel,
// so the top nav is hidden there; those pages keep their own links inside
// the panel. Logged-in users on the membership page still get the sidebar.
$isAuthPage = $isLoginPage
    || $currentPath === url('/signup')
    || ($currentPath === url('/membership') && $user === null);

// 18+ entry gate: shown once per session to guests while it is enabled in
// the admin System page. Members, the login/signup pages and every non-HTML
// endpoint stay reachable without confirmation.
$ageGateEnabled  = \App\Models\SiteConfig::ageGateEnabled();
$ageGateRequired = $user === null && $ageGateEnabled && empty($_SESSION['age_verified']);
$ageReturnTo     = (string) ($_SERVER['REQUEST_URI'] ?? '/');

// Google Analytics loads only after the visitor has given consent (a
// first-party cookie set by the consent banner) AND has passed the 18+
// gate (members are adults by definition). It never loads on the login,
// signup or legal pages.
$gaId         = trim((string) env_value('GA_ID', ''));
$gaConsent    = ($_COOKIE['ga_consent'] ?? '') === '1';
$gaPageOk     = !in_array($currentPath, [url('/login'), url('/signup'), url('/terms'), url('/privacy')], true);
$gaAllowed    = $gaId !== '' && $gaConsent && $gaPageOk && ($user !== null || !empty($_SESSION['age_verified']));
$gaShowBanner = $gaId !== '' && !$gaConsent && $gaPageOk;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="rating" content="RTA-5042-1996-1400-1577-RTA">
    <meta name="icra" content="nudity, sexual content, adult content">
    <?php if ($gaAllowed ?? false): ?>
    <?php require __DIR__ . '/partials/ga.php'; ?>
    <?php endif; ?>
    <title><?= isset($title) ? e($title) . ' — ' . config('app.site_name') : e(config('app.site_name')) ?></title>
    <?php if (isset($metaDescription) && $metaDescription !== ''): ?>
        <meta name="description" content="<?= e($metaDescription) ?>">
    <?php else: ?>
        <meta name="description" content="<?= e(config('app.site_name')) ?> — curated galleries of original photos and videos.">
    <?php endif; ?>
    <?php if (isset($noindex) && $noindex): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <?php if (isset($canonicalUrl) && $canonicalUrl !== ''): ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <link rel="alternate" type="application/rss+xml" title="<?= e(config('app.site_name')) ?> — latest galleries" href="<?= e(absolute_url('/feed.xml')) ?>">
    <?php if (isset($ogImage) && $ogImage !== ''): ?>
        <meta property="og:image" content="<?= e($ogImage) ?>">
        <meta property="og:image:alt" content="<?= e(isset($title) ? $title . ' — ' . config('app.site_name') : config('app.site_name')) ?>">
        <meta name="twitter:image" content="<?= e($ogImage) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_US">
    <meta property="og:title" content="<?= e(isset($title) ? $title . ' — ' . config('app.site_name') : config('app.site_name')) ?>">
    <meta property="og:description" content="<?= e($metaDescription ?? (config('app.site_name') . ' — curated galleries of original photos and videos.')) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl ?? absolute_url('')) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e(isset($title) ? $title . ' — ' . config('app.site_name') : config('app.site_name')) ?>">
    <meta name="twitter:description" content="<?= e($metaDescription ?? (config('app.site_name') . ' — curated galleries of original photos and videos.')) ?>">
    <script nonce="<?= csp_nonce() ?>" type="application/ld+json" nonce="<?= csp_nonce() ?>">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": <?= json_encode(config('app.site_name'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        "url": <?= json_encode(absolute_url(''), JSON_UNESCAPED_SLASHES) ?>
    }
    </script>
    <?php if (isset($ldJson) && is_array($ldJson)): ?>
    <script nonce="<?= csp_nonce() ?>" type="application/ld+json" nonce="<?= csp_nonce() ?>"><?= json_encode($ldJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
    <?php if (!empty($lcpPreload)): ?>
    <link rel="preload" as="image" href="<?= e($lcpPreload) ?>" fetchpriority="high">
    <?php endif; ?>
    <link rel="preload" as="style" href="<?= e(url('/theme.css') . '?v=' . \App\Models\Theme::themeCssVersion($userThemePreset)) ?>">
    <link rel="preload" as="style" href="<?= e(asset_url('/assets/css/user.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/theme.css') . '?v=' . \App\Models\Theme::themeCssVersion($userThemePreset)) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/user.css')) ?>">
</head>
<body<?= $isAuthPage ? '' : ' class="site-compact"' ?> data-base="<?= e(config('app.base_path')) ?>" data-user="<?= $user !== null ? '1' : '0' ?>">
<?php if ($ageGateRequired): ?>
<style>
    .age-gate{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;background:linear-gradient(160deg,#2a1240,#4a1d5f 55%,#7e1d4f);color:#fff;}
    .age-gate-panel{max-width:480px;width:100%;text-align:center;background:#fff;color:#1e1b2e;border-radius:var(--border-radius-lg);padding:2rem;box-shadow:0 24px 60px rgba(0,0,0,.45);}
    .age-gate-panel h1{margin:0 0 .35rem;font-size:1.5rem;}
    .age-gate-logos{font-variant:small-caps;letter-spacing:.12em;color:#8b5ab5;font-size:.8rem;margin:0 0 .5rem;}
    .age-gate-panel p{margin:.5rem 0 1.25rem;color:#5a4d6b;}
    .age-gate-panel .thin-link{color:#8b5ab5;text-decoration:underline;}
    .age-gate-panel .btn{margin:.2rem .25rem;}
</style>
<div class="age-gate" role="dialog" aria-modal="true" aria-labelledby="age-gate-title">
    <div class="age-gate-panel">
        <p class="age-gate-logos">Adults only &middot; 18+</p>
        <h1 id="age-gate-title">Are you over 18?</h1>
        <p>This site contains sexually explicit material intended for adults. By entering you confirm you are at least 18 (or 21 where applicable) and you agree to the <a class="thin-link" href="<?= url('/terms') ?>">Terms of Service</a>. <a class="thin-link" href="<?= url('/2257') ?>">2257</a> &middot; <a class="thin-link" href="<?= url('/dmca') ?>">DMCA</a></p>
        <form method="post" action="<?= url('/age-verify') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($ageReturnTo) ?>">
            <button class="btn" type="submit">I am 18 or older &mdash; enter</button>
        </form>
        <a class="btn btn-outline" href="https://www.google.com" rel="noopener noreferrer">I am not 18 &mdash; exit</a>
    </div>
</div>
<?php endif; ?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if (!empty($_SESSION['impersonator_id'])): ?>
    <div style="background:#7f1d1d;color:#fff;padding:.5rem 1rem;display:flex;gap:1rem;align-items:center;justify-content:center;border-radius:var(--border-radius);margin-bottom:1rem;">
        <b>Impersonating — viewing the site as a member.</b>
        <form method="post" action="<?= url('/admin/impersonate/exit') ?>" style="display:inline;">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-sm" style="background:#fff;color:#7f1d1d;">Return to admin</button>
        </form>
    </div>
    <style>.title-header { display: none; }</style>
<?php endif; ?>
    <header class="title-header">
            <img src="<?= e(url(\App\Models\Theme::userTheme($userThemePreset)['title_image'])) ?>" alt="<?= e(config('app.site_name')) ?>">
    </header>
    <?php if (!$sidebarNav && !$isAuthPage): ?>
    <nav class="nav">
        <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="nav-links-id" id="nav-toggle-id" data-action="galleryNavToggle">&#9776;</button>
        <span class="spacer"></span>
        <div class="nav-links" id="nav-links-id">
        <?php if ($user !== null): ?>
            <a class="btn btn-sm btn-outline" href="<?= url('/settings') ?>" data-se-move-key="top-settings">Settings</a>
            <form class="inline" method="post" action="<?= url('/logout') ?>" data-se-move-key="top-logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
        <?php else: ?>
            <?php if (!$isLoginPage): ?>
                <a class="btn btn-sm" href="<?= url('/login') ?>">Login</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= url('/signup') ?>">Sign Up</a>
        <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>

    <?php foreach ($flash as $flashType => $flashMessages): ?>
        <?php foreach ($flashMessages as $flashMessage): ?>
            <div class="flash <?= e($flashType) ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>"><?= e($flashMessage) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($sidebarNav): ?>
    <div class="home-layout">
        <div class="home-nav-wrap">
        <?php
        // The left sidebar always lists every favourite category. The
        // category currently being displayed is highlighted and moved to the
        // top of the list. The active category comes from the category page
        // ($category) or from the home page's ?category= filter ($categoryId).
        $navCategories    = (array) ($navCategories ?? []);
        $activeCategoryId = isset($category) && is_array($category)
            ? (int) $category['id']
            : (int) ($categoryId ?? 0);

        if ($activeCategoryId > 0) {
            $activeIndex = null;
            foreach ($navCategories as $i => $cat) {
                if ((int) $cat['id'] === $activeCategoryId) {
                    $activeIndex = $i;
                    break;
                }
            }
            if ($activeIndex !== null) {
                $activeCategory = $navCategories[$activeIndex];
                unset($navCategories[$activeIndex]);
                array_unshift($navCategories, $activeCategory);
            }
        }
        ?>
        <?php
        // The member rail mirrors the admin's: an identity block at the top, a
        // scrollable column of icon links grouped by purpose, and the sign-out
        // control pinned at the bottom. Colours still come from the theme's
        // --sidebar-* tokens, so the theme editor keeps working on it.
        $icon = static fn (string $name): string => \App\Core\Icon::svg($name, 'rail-ico');
        $navWallUnread = $user !== null ? \App\Models\Notification::unreadCount((int) $user['id']) : 0;
        $memberName = (string) ($user['email'] ?? '');
        $memberInitial = $memberName !== '' ? strtoupper(substr($memberName, 0, 1)) : '?';
        $memberLevel = \App\Core\Auth::hasActiveSubscription() ? 'Active member' : 'Free account';

        $railGroups = [
            [
                'label' => 'Overview',
                'items' => [
                    ['key' => 'pub-account', 'label' => 'Dashboard', 'href' => '/account', 'icon' => 'grid',
                     'active' => $currentPath === url('/account')],
                ],
            ],
            [
                'label' => 'Browse',
                'items' => [
                    ['key' => 'pub-galleries', 'label' => 'Galleries', 'href' => '/galleries', 'icon' => 'image',
                     'active' => strpos($currentPath, url('/galleries')) === 0],
                    ['key' => 'pub-favorites', 'label' => 'Favorites', 'href' => '/favorites', 'icon' => 'heart',
                     'active' => strpos($currentPath, url('/favorites')) === 0,
                     'badge' => ($user !== null && !\App\Core\Auth::hasActiveSubscription())
                        ? '<span class="nav-gated" title="Favorites require a Silver or higher membership">Silver</span>' : ''],
                    ['key' => 'pub-collections', 'label' => 'Collections', 'href' => '/collections', 'icon' => 'book',
                     'active' => strpos($currentPath, url('/collections')) === 0],
                ],
            ],
            [
                'label' => 'Community',
                'items' => [
                    ['key' => 'pub-wall', 'label' => 'Wall', 'href' => '/wall', 'icon' => 'users',
                     'active' => strpos($currentPath, url('/wall')) === 0,
                     'badge' => $navWallUnread > 0 ? '<span class="nav-unread" aria-label="' . (int) $navWallUnread . ' unread notifications">' . (int) $navWallUnread . '</span>' : ''],
                    ['key' => 'pub-chat', 'label' => 'Chat', 'href' => '/chat', 'icon' => 'chat', 'show' => $user !== null,
                     'active' => strpos($currentPath, url('/chat')) === 0,
                     // canChat() needs a real user row, so this must not run for guests.
                     'badge' => ($user !== null && \App\Models\ChatMessage::canChat((int) $user['id']) && !empty($chatUnreadCount))
                        ? '<span class="nav-unread" aria-label="' . (int) $chatUnreadCount . ' unread replies">' . (int) $chatUnreadCount . '</span>' : ''],
                    ['key' => 'pub-live', 'label' => 'Live', 'href' => '/live', 'icon' => 'broadcast',
                     'show' => $user !== null && \App\Core\Auth::hasActiveSubscription(),
                     'active' => strpos($currentPath, url('/live')) === 0],
                ],
            ],
            [
                'label' => 'Account',
                'items' => [
                    ['key' => 'pub-membership', 'label' => 'Membership', 'href' => '/membership', 'icon' => 'card',
                     'active' => strpos($currentPath, url('/membership')) === 0],
                    ['key' => 'pub-support', 'label' => 'Support', 'href' => '/support', 'icon' => 'lifebuoy',
                     'active' => strpos($currentPath, url('/support')) === 0,
                     'badge' => !empty($supportUnreadCount) ? '<span class="nav-unread" aria-label="' . (int) $supportUnreadCount . ' unread replies">' . (int) $supportUnreadCount . '</span>' : ''],
                    ['key' => 'pub-settings', 'label' => 'Settings', 'href' => '/settings', 'icon' => 'sliders',
                     'active' => strpos($currentPath, url('/settings')) === 0],
                    ['key' => 'pub-admin', 'label' => 'Admin', 'href' => '/admin', 'icon' => 'server',
                     'show' => $user !== null && \App\Core\Auth::isAdmin()],
                ],
            ],
        ];
        ?>
        <nav class="home-nav-actions user-nav" aria-label="Site menu">
            <?php if ($user !== null): ?>
                <a class="rail-identity<?= $currentPath === url('/account') ? ' active' : '' ?>" href="<?= url('/account') ?>"
                   data-se-move-key="pub-dashboard" title="<?= e($memberName) ?>">
                    <span class="rail-avatar" aria-hidden="true"><?= e($memberInitial) ?></span>
                    <span class="rail-identity-text">
                        <strong><?= e($memberName) ?></strong>
                        <small><?= e($memberLevel) ?></small>
                    </span>
                </a>
            <?php else: ?>
                <div class="rail-identity rail-guest">
                    <span class="rail-avatar" aria-hidden="true">?</span>
                    <span class="rail-identity-text">
                        <strong>Guest</strong>
                        <small><a href="<?= url('/login') ?>">Sign in</a> or <a href="<?= url('/signup') ?>">join</a></small>
                    </span>
                </div>
            <?php endif; ?>
            <div class="rail-scroll">
                <div class="rail-main">
                <?php foreach ($railGroups as $group): ?>
                    <div class="rail-group"><?= e($group['label']) ?></div>
                    <?php foreach ($group['items'] as $item): ?>
                        <?php if (isset($item['show']) && !$item['show']) { continue; } ?>
                        <a class="nav-item<?= $item['active'] ? ' active' : '' ?>" href="<?= e(url($item['href'])) ?>"
                           data-se-move-key="<?= e($item['key']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                            <?= $icon($item['icon']) ?><span><?= e($item['label']) ?></span><?= $item['badge'] ?? '' ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </div>
                <div class="rail-categories">
                <?php if (strpos($currentPath, url('/chat')) !== 0): ?>
                    <div class="rail-group">Favorite categories</div>
                    <?php if (empty($navCategories)): ?>
                        <p class="muted rail-empty">No favorite categories yet.</p>
                    <?php else: ?>
                        <?php foreach ($navCategories as $cat): ?>
                            <?php // Keep the current type filter (images/videos) on the category link. ?>
                            <?php $isActive = (int) $cat['id'] === $activeCategoryId; ?>
                            <a class="nav-item rail-category<?= $isActive ? ' active' : '' ?>" href="<?= url('/galleries/category/' . e($cat['slug']) . $typeSuffix) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= $icon('tag') ?><span><?= e($cat['name']) ?></span></a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </div>
            <?php if ($user !== null): ?>
                <div class="rail-foot">
                    <form class="nav-logout" method="post" action="<?= url('/logout') ?>" data-se-move-key="pub-logout">
                        <?= csrf_field() ?>
                        <button type="submit" class="rail-logout-btn" aria-label="Sign out" title="Sign out"><?= $icon('logout') ?><span>Sign out</span></button>
                    </form>
                </div>
            <?php endif; ?>
        </nav>
        </div>
        <main id="main-content" class="home-main">
            <?php echo $content; ?>
        </main>
    </div>
    <?php else: ?>
    <main id="main-content"><?php echo $content; ?></main>
    <?php endif; ?>
    <?php if ($sidebarNav && $user !== null): ?>
    <nav class="media-bottom-nav" aria-label="Mobile site menu">
        <a href="<?= url('/account') ?>">Dashboard</a>
        <a href="<?= url('/galleries') ?>">Galleries</a>
        <a href="<?= url('/favorites') ?>">Favorites</a>
        <a href="<?= url('/collections') ?>">Collections</a>
        <a href="<?= url('/membership') ?>">Membership</a>
         <a href="<?= url('/support') ?>">Support<?php if (!empty($supportUnreadCount)): ?> <span class="nav-unread"><?= (int) $supportUnreadCount ?></span><?php endif; ?></a>
        <a href="<?= url('/wall') ?>">Wall<?php if ($navWallUnread > 0): ?> <span class="nav-unread"><?= $navWallUnread ?></span><?php endif; ?></a>
        <?php if ($user !== null && \App\Core\Auth::hasActiveSubscription()): ?>
        <a href="<?= url('/live') ?>">Live</a>
        <?php endif; ?>
        <a href="<?= url('/settings') ?>">Settings</a>
    </nav>
    <?php endif; ?>
    <?php if ($user !== null && !\App\Core\Auth::isAdmin()): ?>
    <aside id="member-onboarding" class="member-onboarding" hidden role="dialog" aria-modal="true" aria-labelledby="member-onboarding-title">
        <div class="member-onboarding-card">
            <h2 id="member-onboarding-title">Welcome to your member area</h2>
            <p>Use these shortcuts to get the most from your account:</p>
            <ul>
                <li><a href="<?= e(url('/account')) ?>"><strong>Dashboard</strong></a> for recently viewed galleries and uploads.</li>
                <li><a href="<?= e(url('/galleries')) ?>"><strong>Galleries</strong></a> to browse and search the collection.</li>
                <li><strong>Favorites</strong> to quickly return to galleries and categories you save.</li>
                <li><a href="<?= e(url('/support')) ?>"><strong>Support</strong></a> for questions or help with media.</li>
            </ul>
            <button type="button" class="btn btn-sm" data-dismiss-onboarding>Got it</button>
        </div>
    </aside>
    <?php endif; ?>
    <script nonce="<?= csp_nonce() ?>">
        // Gallery cards: if a card's category chips don't all fit on one row,
        // collapse them to that row and show an expand/collapse toggle.
        (function () {
            function initCardCats() {
                document.querySelectorAll('.card-cats').forEach(function (wrap) {
                    var chips = Array.prototype.slice.call(wrap.children);
                    if (chips.length < 2) { return; }

                    var wrapWidth = wrap.clientWidth;
                    var gap = parseFloat(getComputedStyle(wrap).gap) || 0;
                    if (!gap) {
                        var fontSize = parseFloat(getComputedStyle(wrap).fontSize) || 16;
                        gap = Math.round(0.35 * fontSize);
                    }

                    var count = 0;
                    var row = 0;
                    for (var i = 0; i < chips.length; i++) {
                        var w = chips[i].offsetWidth;
                        if (count > 0 && row + gap + w > wrapWidth) { break; }
                        row += (count > 0 ? gap : 0) + w;
                        count++;
                    }

                    var toggle = wrap.nextElementSibling;
                    if (count >= chips.length) {
                        wrap.classList.remove('collapsible', 'open');
                        wrap.style.maxHeight = '';
                        if (toggle) { toggle.hidden = true; }
                        return;
                    }

                    wrap.classList.add('collapsible');
                    var firstRowH = 0;
                    for (var j = 0; j < count; j++) {
                        firstRowH = Math.max(firstRowH, chips[j].offsetHeight);
                    }
                    wrap.style.maxHeight = firstRowH + 'px';

                    if (toggle) {
                        toggle.hidden = false;
                        toggle.onclick = function () {
                            var open = wrap.classList.toggle('open');
                            wrap.style.maxHeight = open ? '' : firstRowH + 'px';
                            toggle.textContent = open ? 'Show fewer' : 'Show more (' + (chips.length - count) + ')';
                        };
                    }
                });
            }

            initCardCats();
            window.addEventListener('resize', initCardCats);
            window.addEventListener('load', initCardCats);

        })();
    </script>
    <script nonce="<?= csp_nonce() ?>">
        // Accessible mobile nav: track the expanded state on the toggle button
        // and close the dropdown on outside clicks, Escape, or following a link.
        window.GalleryNav = {
            toggle: function () {
                var btn = document.getElementById('nav-toggle-id');
                var links = document.getElementById('nav-links-id');
                if (!btn || !links) return;
                var open = links.classList.toggle('open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            },
            close: function () {
                var btn = document.getElementById('nav-toggle-id');
                var links = document.getElementById('nav-links-id');
                if (!btn || !links) return;
                links.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        };
        document.addEventListener('click', function (e) {
            var links = document.getElementById('nav-links-id');
            if (!links) return;
            var inside = links.contains(e.target) || (e.target.id && e.target.id === 'nav-toggle-id');
            if (!inside) window.GalleryNav.close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.GalleryNav.close();
        });
    </script>
<?php if (!empty($_GET['se']) && in_array($_GET['se'], ['1', 'user'], true)): ?>
    <script nonce="<?= csp_nonce() ?>">
    (function(){
        function keepPreview(url){
            try{var u=new URL(url,window.location.href),mode=new URLSearchParams(window.location.search).get('se')||'user';if(u.origin===window.location.origin)u.searchParams.set('se',mode);return u.href;}catch(e){return url;}
        }
        document.addEventListener('click',function(e){var a=e.target.closest('a[href]');if(a&&!a.target&&a.href)a.href=keepPreview(a.href);},true);
        document.addEventListener('submit',function(e){if(e.target.action)e.target.action=keepPreview(e.target.action);},true);
    })();
    </script>
<?php endif; ?>
<?php
$_activeSiteTpl = \App\Models\SiteTemplate::active(\App\Models\SiteTemplate::SCOPE_USER);
if ($_activeSiteTpl !== null && empty($_GET['se'])):
$_tplChanges = json_decode((string) $_activeSiteTpl['config_json'], true) ?: [];
$_tplJson = json_encode($_tplChanges, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>
    <script nonce="<?= csp_nonce() ?>">
    (function(){
        var changes=<?= $_tplJson ?>;
        function applyOrder(c){
            var p=c.parentKey==='body'?document.body:null;
            if(c.parentOrigin){p=document.querySelector(c.parentOrigin)||p;if(p&&c.parentKey)p.setAttribute('data-se-move-key',c.parentKey);}
            if(!p)return;
            (c.items||[]).map(function(item){var el=item.key?document.querySelector('[data-se-move-key="'+item.key+'"]'):null;if(!el&&item.origin)el=document.querySelector(item.origin);if(el&&item.key&&!el.hasAttribute('data-se-move-key'))el.setAttribute('data-se-move-key',item.key);if(el&&item.styles)Object.keys(item.styles).forEach(function(k){if(item.styles[k])el.style.setProperty(k,item.styles[k]);});return el;}).filter(Boolean).forEach(function(el){p.appendChild(el);});
        }
        changes.forEach(function(c){
            try{
                if(c.type==='order'){applyOrder(c);return;}
                var el=c.key?document.querySelector('[data-se-move-key="'+c.key+'"]'):null;
                if(!el&&c.origin){el=document.querySelector(c.origin);if(el&&c.key)el.setAttribute('data-se-move-key',c.key);}
                if(!el)el=document.querySelector(c.selector);
                if(!el)return;
                if(c.type==='hide'||c.type==='delete')el.style.display='none';
                else if(c.type==='move'){
                    if(c.anchor||c.parent){
                        var a=c.anchorKey?document.querySelector('[data-se-move-key="'+c.anchorKey+'"]'):null;
                        if(!a&&c.anchorOrigin){a=document.querySelector(c.anchorOrigin);if(a&&c.anchorKey)a.setAttribute('data-se-move-key',c.anchorKey);}
                        if(!a&&c.anchor)a=document.querySelector(c.anchor);
                        if(a&&a!==el&&!el.contains(a)){
                            if(c.position==='before')a.parentNode.insertBefore(el,a);
                            else a.parentNode.insertBefore(el,a.nextSibling);
                        }else{
                            var p=c.parent==='body'?document.body:document.querySelector(c.parent);
                            if(p&&c.position==='append')p.appendChild(el);
                        }
                    }else{
                        var vw=document.documentElement.clientWidth||1,vh=document.documentElement.clientHeight||1;
                        var rect=el.getBoundingClientRect();
                        var mx=c.targetXRatio!=null?c.targetXRatio*vw-rect.left:(c.dxRatio!=null?c.dxRatio*vw:(c.dx||0));
                        var my=c.targetYRatio!=null?c.targetYRatio*vh-rect.top:(c.dyRatio!=null?c.dyRatio*vh:(c.dy||0));
                        el.style.setProperty('transform','translate('+mx+'px,'+my+'px)','important');
                    }
                }
                else if(c.type==='restyle')Object.keys(c.styles||{}).forEach(function(k){el.style[k]=c.styles[k]});
                else if(c.type==='add'){
                    var t=c.parent?document.querySelector(c.parent):document.body;
                    if(t){var d=document.createElement(c.tag||'div');d.className='se-added-element';d.setAttribute('data-se-added','1');d.innerHTML=c.html||'';
                    if(c.styles)Object.keys(c.styles).forEach(function(k){d.style[k]=c.styles[k]});
                    if(c.position==='prepend')t.prepend(d);else if(c.position==='before')t.parentElement.insertBefore(d,t);
                    else if(c.position==='after')t.parentElement.insertBefore(d,t.nextSibling);else t.appendChild(d);}
                }
            }catch(e){}
        });
    })();
    </script>
<?php endif; ?>
    <?php require __DIR__ . '/partials/footer.php'; ?>
    <?php require __DIR__ . '/partials/consent_banner.php'; ?>
    <script nonce="<?= csp_nonce() ?>">try{var p=JSON.parse(localStorage.getItem('galleryDisplayPrefs')||'{}');var v=p.view||'grid';var s=p.size||'md';document.documentElement.classList.add('g-view-'+v);document.documentElement.classList.add('g-size-'+s);if(p.masonry)document.documentElement.classList.add('g-masonry');}catch(e){document.documentElement.classList.add('g-view-grid');document.documentElement.classList.add('g-size-md');}</script>
    <?php require __DIR__ . '/partials/tour_targets.php'; ?>
    <script nonce="<?= csp_nonce() ?>">
// Nonce'd inline-handler replacement (strict CSP, no script-src-attr).
// Views express behaviour via data-* attributes; this module delegates:
//   data-confirm   -> window.confirm() gate on submit OR click
//   data-action    -> calls window[actionName](el, e) (or [(el)] with data-arg)
//   data-autosubmit-> submits the host form on 'change'
//   data-copy      -> copies the value of #<id> to the clipboard
//   data-picker    -> fires input.showPicker() (date inputs)
//   data-nocontext -> prevents the context menu on the element
//   data-fallback  -> img error fallback src (applied once)
//   data-stop-prop -> click does not bubble past the element
window.galleryNavToggle=function(){ if(window.GalleryNav){window.GalleryNav.toggle();} };
// Copy text using the async Clipboard API where available (secure context),
// falling back to a hidden textarea + execCommand for plain-http setups (dev).
window.__copyText=function(text){
  text=String(text);
  if(navigator.clipboard&&window.isSecureContext){
    return navigator.clipboard.writeText(text);
  }
  return new Promise(function(resolve,reject){
    var i=document.createElement('textarea');
    i.value=text; i.setAttribute('readonly','');
    i.style.position='fixed'; i.style.top='-1000px'; i.style.opacity='0';
    document.body.appendChild(i);
    var ok=false;
    try{ i.focus(); i.select(); i.setSelectionRange(0,text.length); ok=document.execCommand('copy'); }catch(_e){}
    i.remove();
    ok?resolve():reject(new Error('copy failed'));
  });
};
window.__copied=function(el,ok){
  if(!el){return;}
  if(el.getAttribute('data-copy-label')===null){el.setAttribute('data-copy-label',el.textContent);}
  el.textContent=ok?'Copied':'Copy failed';
  clearTimeout(el.__copyTimer);
  el.__copyTimer=setTimeout(function(){ el.textContent=el.getAttribute('data-copy-label'); },1500);
};
window.copyToClipboard=function(sel, el){
  var node=sel&&sel.nodeName&&sel.nodeType?sel:document.getElementById(String(sel));
  if(!node){return;}
  var text=('value' in node)?node.value:node.textContent;
  window.__copyText(text).then(function(){ window.__copied(el,true); },function(){ window.__copied(el,false); });
};
window.showPickerOn=function(el){ if(el){try{el.showPicker&&el.showPicker();}catch(_e){}} };
window.chatFocusInput=function(){ var i=document.getElementById('chat-input'); if(i){i.focus();} };
window.startSiteTour=function(){ if(window.GalleryTour){GalleryTour.clearSeen();GalleryTour.start();} };
window.addLinkRow=function(){ var r=document.getElementById('link-rows'); if(r&&r.children[0]){var d=r.children[0].cloneNode(true);d.querySelectorAll('input').forEach(function(i){i.value='';});r.appendChild(d);} };
window.toggleBulkRole=function(sel){ var el=document.getElementById('bulk-role'); if(el){el.style.display=(sel&&sel.value==='role')?'':'none';} };
window.bulkApply=function(btn){
  var n=document.querySelectorAll('.user-check:checked').length;
  if(!n){alert('No users selected.');return;}
  if(!confirm('Apply bulk action to '+n+' checked user(s)?')){return;}
  btn.form.submit();
};
window.collectionActionSelect=function(sel){
  var base=sel.getAttribute('data-col-action')||'<?= url('/collections') ?>/';
  var suffix=sel.getAttribute('data-col-suffix')||'photos';
  if(sel.form&&sel.value&&sel.form.action.indexOf(base)>=0){sel.form.action=base+sel.value+'/'+suffix;}
  if(sel.form){sel.form.submit();}
};
window.copyLinkValue=function(value,el){
  window.__copyText(value).then(function(){ window.__copied(el,true); },function(){ window.__copied(el,false); });
};
document.addEventListener('focusin',function(e){
  var t=e.target;
  if(t&&t.hasAttribute('data-picker-focus')){window.showPickerOn(t);}
},true);
document.addEventListener('submit',function(e){
  var t=e.target,m;
  if(t&&t.tagName==='FORM'&&(m=t.getAttribute('data-confirm'))&&!window.confirm(m)){e.preventDefault();e.stopPropagation();}
},true);
document.addEventListener('change',function(e){
  var t=e.target;
  if(t&&t.hasAttribute('data-autosubmit')&&t.form){t.form.submit();}
  if(t&&t.hasAttribute('data-action')){var a=t.getAttribute('data-action');if(window[a]){window[a](t,e);}}
},true);
document.addEventListener('click',function(e){
  var t=e.target&&e.target.closest?e.target.closest('[data-confirm],[data-action],[data-copy],[data-picker],[data-stop-prop]'):null;
  if(!t){return;}
  if(t.hasAttribute('data-stop-prop')){e.stopPropagation();return;}
  var m=t.getAttribute('data-confirm');
  if(m&&!window.confirm(m)){e.preventDefault();e.stopPropagation();return;}
  var a=t.getAttribute('data-action');
  if(a&&window[a]){ if(t.hasAttribute('data-arg')){window[a](t.getAttribute('data-arg'),t);} else {window[a](t,e);} e.preventDefault(); }
  if(t.hasAttribute('data-copy')){window.copyToClipboard(t.getAttribute('data-copy'),t);e.preventDefault();}
  if(t.hasAttribute('data-picker')){window.showPickerOn(t);e.preventDefault();}
},true);
document.addEventListener('contextmenu',function(e){
  if(e.target&&e.target.closest&&e.target.closest('[data-nocontext]')){e.preventDefault();}
},true);
document.addEventListener('error',function(e){
  var t=e.target;
  if(!t){return;}
  if(t.tagName==='IMG'&&t.hasAttribute('data-hide-on-error')){t.style.display='none';return;}
  if(t.tagName==='IMG'&&t.hasAttribute('data-fallback')&&!t.getAttribute('data-fb')){
    t.setAttribute('data-fb','1');
    t.src=t.getAttribute('data-fallback');
  }
},true);
    </script>
    <script nonce="<?= csp_nonce() ?>" src="<?= url('/assets/js/user.js') ?>?v=16" defer></script>
    <script nonce="<?= csp_nonce() ?>" src="<?= url('/assets/js/tour.js') ?>?v=9" defer></script>
</body>
</html>
