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
    <script type="application/ld+json" nonce="<?= csp_nonce() ?>">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": <?= json_encode(config('app.site_name'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        "url": <?= json_encode(absolute_url(''), JSON_UNESCAPED_SLASHES) ?>
    }
    </script>
    <?php if (isset($ldJson) && is_array($ldJson)): ?>
    <script type="application/ld+json" nonce="<?= csp_nonce() ?>"><?= json_encode($ldJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('/theme.css') . '?v=' . \App\Models\Theme::themeCssVersion($userThemePreset)) ?>">
    <link rel="stylesheet" href="<?= url('/assets/css/user.css') ?>?v=32">
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
        <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="nav-links-id" id="nav-toggle-id" onclick="GalleryNav.toggle()">&#9776;</button>
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
        <nav class="home-nav-actions user-nav" aria-label="Site menu">
            <a class="nav-brand" href="<?= url('/account') ?>" data-se-move-key="pub-dashboard">Dashboard</a>
            <a class="nav-item<?= $currentPath === url('/account') ? ' active' : '' ?>" href="<?= url('/account') ?>" data-se-move-key="pub-account">Dashboard</a>
            <a class="nav-item<?= strpos($currentPath, url('/galleries')) === 0 ? ' active' : '' ?>" href="<?= url('/galleries') ?>" data-se-move-key="pub-galleries">Galleries</a>
            <a class="nav-item<?= strpos($currentPath, url('/favorites')) === 0 ? ' active' : '' ?>" href="<?= url('/favorites') ?>" data-se-move-key="pub-favorites">Favorites<?php if ($user !== null && !\App\Core\Auth::hasActiveSubscription()): ?> <span class="nav-gated" title="Favorites require a Silver or higher membership">Silver</span><?php endif; ?></a>
            <a class="nav-item<?= strpos($currentPath, url('/collections')) === 0 ? ' active' : '' ?>" href="<?= url('/collections') ?>" data-se-move-key="pub-collections">Collections</a>
            <a class="nav-item<?= strpos($currentPath, url('/membership')) === 0 ? ' active' : '' ?>" href="<?= url('/membership') ?>" data-se-move-key="pub-membership">Membership</a>
            <a class="nav-item<?= strpos($currentPath, url('/support')) === 0 ? ' active' : '' ?>" href="<?= url('/support') ?>" data-se-move-key="pub-support">Support<?php if (!empty($supportUnreadCount)): ?> <span class="nav-unread" aria-label="<?= (int) $supportUnreadCount ?> unread replies"><?= (int) $supportUnreadCount ?></span><?php endif; ?></a>
            <?php $navWallUnread = $user !== null ? \App\Models\Notification::unreadCount((int) $user['id']) : 0; ?>
            <a class="nav-item<?= strpos($currentPath, url('/wall')) === 0 ? ' active' : '' ?>" href="<?= url('/wall') ?>" data-se-move-key="pub-wall">Wall<?php if ($navWallUnread > 0): ?> <span class="nav-unread" aria-label="<?= $navWallUnread ?> unread notifications"><?= $navWallUnread ?></span><?php endif; ?></a>
            <?php if ($user !== null): ?>
                <a class="nav-item<?= strpos($currentPath, url('/chat')) === 0 ? ' active' : '' ?>" href="<?= url('/chat') ?>" data-se-move-key="pub-chat">Chat<?php if (\App\Models\ChatMessage::canChat((int) $user['id']) && !empty($chatUnreadCount)): ?> <span class="nav-unread" aria-label="<?= (int) $chatUnreadCount ?> unread replies"><?= (int) $chatUnreadCount ?></span><?php endif; ?></a>
            <?php endif; ?>
            <?php if ($user !== null && \App\Core\Auth::hasActiveSubscription()): ?>
                <a class="nav-item<?= strpos($currentPath, url('/live')) === 0 ? ' active' : '' ?>" href="<?= url('/live') ?>" data-se-move-key="pub-live">Live</a>
            <?php endif; ?>
            <?php if ($user !== null && \App\Core\Auth::isAdmin()): ?>
                <a class="nav-item" href="<?= url('/admin') ?>" data-se-move-key="pub-admin">Admin</a>
            <?php endif; ?>
            <a class="nav-item<?= strpos($currentPath, url('/settings')) === 0 ? ' active' : '' ?>" href="<?= url('/settings') ?>" data-se-move-key="pub-settings">Settings</a>
            <form class="nav-logout" method="post" action="<?= url('/logout') ?>" data-se-move-key="pub-logout">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
            <div class="nav-sep"></div>
            <?php if (strpos($currentPath, url('/chat')) !== 0): ?>
                <div class="nav-section-label">Favorite categories</div>
                <?php if (empty($navCategories)): ?>
                    <p class="muted nav-empty">No favorite categories yet.</p>
                <?php else: ?>
                    <?php foreach ($navCategories as $cat): ?>
                        <?php // Keep the current type filter (images/videos) on the category link. ?>
                        <?php $isActive = (int) $cat['id'] === $activeCategoryId; ?>
                        <a class="nav-item<?= $isActive ? ' active' : '' ?>" href="<?= url('/galleries/category/' . e($cat['slug']) . $typeSuffix) ?>"><?= e($cat['name']) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="nav-sep"></div>
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
    <script nonce="<?= csp_nonce() ?>" src="<?= url('/assets/js/user.js') ?>?v=16" defer></script>
    <script nonce="<?= csp_nonce() ?>" src="<?= url('/assets/js/tour.js') ?>?v=8" defer></script>
</body>
</html>
