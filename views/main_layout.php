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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($title) ? e($title) . ' — ' . config('app.site_name') : e(config('app.site_name')) ?></title>
    <style>
<?= \App\Models\Theme::css() ?>
        body { font-family: sans-serif; max-width: none; width: 100%; margin: 0; padding: 1rem 1.5rem; box-sizing: border-box; color: var(--purple-900); background: var(--pink-200); }
        a { color: var(--purple-600); }
        .nav { display: flex; gap: 1rem; align-items: center; border-bottom: 1px solid var(--pink-300); padding-bottom: 0.5rem; margin-bottom: 1.5rem; }
        .nav a { text-decoration: none; color: var(--purple-700); }
        .nav .brand { font-weight: bold; font-size: 1.2rem; color: var(--purple-900); }
        .nav .spacer { flex: 1; }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], textarea, select { padding: 0.4rem 0.6rem; border: 1px solid var(--pink-300); border-radius: 4px; background: var(--pink-100); color: var(--purple-900); }
        input:focus, textarea:focus, select:focus { outline: 2px solid var(--purple-400); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; margin-top: 1rem; }
        .grid img { width: 100%; height: 150px; object-fit: cover; border-radius: 4px; cursor: pointer; }
        .grid video { width: 100%; height: auto; object-fit: contain; border-radius: 4px; background: #000; display: block; cursor: pointer; }
        .video-open { position: relative; display: block; }
        .play-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: rgba(255, 255, 255, 0.9); text-shadow: 0 0 12px rgba(0, 0, 0, 0.7); pointer-events: none; }
        .title-header { text-align: center; margin-bottom: 1.25rem; }
        .title-header img { max-width: 100%; height: auto; border-radius: 10px; }
        .card { border: 1px solid var(--pink-400); border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: var(--pink-300); box-shadow: 0 1px 4px rgba(88, 28, 135, 0.15); }
        .card a { text-decoration: none; color: inherit; }
        .card-link { display: block; }
        .card img, .card-placeholder { width: 100%; height: 180px; object-fit: cover; border-radius: 6px; display: block; background: var(--pink-100); }
        .card-cover { position: relative; }
        .card-cover .video-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: rgba(255, 255, 255, 0.9); text-shadow: 0 0 12px rgba(0, 0, 0, 0.7); pointer-events: none; }
        .card h2 { margin: 0.5rem 0 0.25rem; font-size: 1.1rem; color: var(--purple-800); }
        .btn { display: inline-block; padding: 0.5rem 1rem; background: var(--pink-300); color: var(--purple-900); text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
        .btn:hover { background: var(--pink-400); }
        .btn-danger { background: var(--pink-100); color: var(--purple-900); border: 1px solid var(--pink-600); }
        .btn-danger:hover { background: var(--pink-200); }
        .btn-sm { padding: 0.25rem 0.6rem; font-size: 0.85rem; }
        .btn-outline { background: var(--pink-100); color: var(--purple-700); border: 1px solid var(--pink-400); }
        .btn-outline:hover { background: var(--pink-200); }
        form.inline { display: inline; }
        table { border-collapse: collapse; width: 100%; background: var(--pink-100); }
        th, td { border: 1px solid var(--pink-300); padding: 0.5rem; text-align: left; vertical-align: middle; color: var(--purple-900); }
        th { background: var(--pink-300); color: var(--purple-900); }
        .flash { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; color: var(--purple-900); }
        .flash.error { background: var(--pink-300); border-left: 4px solid var(--pink-600); }
        .flash.success { background: var(--pink-200); border-left: 4px solid var(--purple-500); }
        .pagination { display: flex; gap: 0.5rem; margin-top: 1.5rem; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 0.35rem 0.7rem; border: 1px solid var(--pink-300); border-radius: 4px; text-decoration: none; color: var(--purple-700); background: var(--pink-100); }
        .pagination .current { background: var(--pink-400); color: var(--purple-900); border-color: var(--pink-400); }
        .grid-link { display: block; }
        .media-nav { display: flex; gap: 0.75rem; align-items: center; justify-content: center; flex-wrap: wrap; margin: 1rem 0; }
        .btn-disabled { opacity: 0.5; cursor: not-allowed; }
        .hero { padding: 1rem 0 1.5rem; text-align: center; }
        .hero form { display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap; }
        .auth-panel { max-width: 420px; margin: 2rem auto; padding: 1.5rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; }
        .auth-panel h1 { text-align: center; margin-top: 0; color: var(--purple-800); }
        .auth-panel .auth-links { text-align: center; margin-bottom: 0; }
        .auth-panel input[type="email"], .auth-panel input[type="password"] { width: 100%; box-sizing: border-box; }
        .hero input[type="text"] { padding: 0.7rem 1rem; font-size: 1rem; border: 2px solid var(--purple-400); border-radius: 6px; width: 60%; min-width: 220px; }
        .chips { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 1rem 0; align-items: center; }
        .chip { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.75rem; border: 1px solid var(--pink-400); border-radius: 999px; background: var(--pink-300); color: var(--purple-700); text-decoration: none; }
        .chip a { text-decoration: none; color: inherit; }
        .chip .star { background: none; border: none; cursor: pointer; font-size: 1.05rem; line-height: 1; padding: 0; color: inherit; }
        .chip .star:hover { color: var(--purple-500); }
        .chip:hover { background: var(--pink-400); }
        .chip.fav { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .chip.fav .star { color: var(--purple-900); }
        .chip.active { background: var(--pink-500); border-color: var(--pink-600); color: var(--purple-900); }
        .chip .star { color: var(--purple-500); font-size: 0.85rem; cursor: pointer; }
        .chip.active .star { color: var(--purple-900); }
        .chip:has(input[type="checkbox"]) { cursor: pointer; }
        .chip input[type="checkbox"] { display: none; }
        .chip:has(input[type="checkbox"]:checked) { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .favorite-option.selected { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .fav-section { margin-top: 2rem; }
        .fav-section h2 { color: var(--purple-800); border-bottom: 2px solid var(--pink-300); padding-bottom: 0.35rem; }
        .fav-section h2 a { font-size: 0.85rem; font-weight: normal; color: var(--purple-600); text-decoration: none; float: right; }
        .section-title { color: var(--purple-800); border-bottom: 2px solid var(--pink-300); padding-bottom: 0.35rem; }
        .muted { color: var(--purple-800); opacity: 0.75; }
        .home-layout { display: flex; gap: 1.5rem; align-items: flex-start; }
        .home-nav-wrap { flex: 0 0 220px; display: flex; flex-direction: column; gap: 0.75rem; position: sticky; top: 1rem; }
        .home-nav-actions, .home-nav { display: flex; flex-direction: column; gap: 0.35rem; padding: 1rem; background: var(--pink-100); border: 1px solid var(--pink-300); border-radius: 10px; }
        .home-nav-actions { gap: 0.5rem; }
        .home-nav-actions .btn { display: block; width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem; border-radius: 6px; text-align: center; }
        .home-nav-actions form { margin: 0; }
        .home-nav-actions h2 { margin: 0 0 0.25rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--purple-800); text-align: center; }
        .home-nav h2 { margin: 0 0 0.5rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--purple-800); text-align: center; }
        .home-nav-link { display: block; padding: 0.5rem 0.75rem; border-radius: 6px; color: var(--purple-700); text-decoration: none; background: var(--pink-300); border: 1px solid var(--pink-400); }
        .home-nav-link:hover { background: var(--pink-400); }
        .home-nav-link.active { background: var(--purple-400); color: var(--purple-900); border-color: var(--purple-500); font-weight: bold; }
        .home-nav-link.active:hover { background: var(--purple-500); }
        .card-cats { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.5rem; overflow: hidden; }
        .card-cats-toggle { display: block; margin-top: 0.4rem; padding: 0.15rem 0; background: none; border: none; color: var(--purple-600); text-decoration: underline; cursor: pointer; font-size: 0.85rem; }
        .card-cats-toggle[hidden] { display: none; }
        .home-main { flex: 1; min-width: 0; }
         @media (max-width: 768px) { .home-layout { flex-direction: column; } .home-nav-wrap { flex: none; width: 100%; padding-top: 0 !important; position: static; } }
        .recent-card { cursor: pointer; }
        .recent-strip { display: flex; flex-wrap: nowrap; gap: 1rem; margin-top: 1rem; overflow: hidden; justify-content: space-between; }
        .recent-strip .card { width: 220px; flex: 0 0 auto; box-sizing: border-box; }
    </style>
</head>
<body>
    <header class="title-header">
        <img src="<?= url('/assets/images/AmethystTitleImage.png') ?>" alt="<?= e(config('app.site_name')) ?>">
    </header>
    <?php if (!$sidebarNav): ?>
    <nav class="nav">
        <span class="spacer"></span>
        <?php if ($user !== null): ?>
            <a class="btn btn-sm btn-outline" href="<?= url('/membership') ?>">Membership</a>
            <a class="btn btn-sm btn-outline" href="<?= url('/settings') ?>">Settings</a>
            <form class="inline" method="post" action="<?= url('/logout') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
        <?php else: ?>
            <?php // Hide the Login button while on the login page itself. ?>
            <?php if (!$isLoginPage): ?>
                <a class="btn btn-sm" href="<?= url('/login') ?>">Login</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= url('/signup') ?>">Sign Up</a>
            <a class="btn btn-sm btn-outline" href="<?= url('/membership') ?>">Membership</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php foreach ($flash as $flashType => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="flash <?= e($flashType) ?>"><?= e($message) ?></div>
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
        <nav class="home-nav-actions" aria-label="Site menu">
            <h2>Menu</h2>
            <a class="btn btn-sm btn-outline" href="<?= url('/galleries') ?>">Galleries</a>
            <?php // The Admin button only appears for admin accounts. ?>
            <?php if ($user !== null && \App\Core\Auth::isAdmin()): ?>
                <a class="btn btn-sm btn-outline" href="<?= url('/admin') ?>">Admin</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= url('/membership') ?>">Membership</a>
            <a class="btn btn-sm btn-outline" href="<?= url('/settings') ?>">Settings</a>
            <form class="inline" method="post" action="<?= url('/logout') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
        </nav>
        <aside class="home-nav">
            <h2>Favorites</h2>
            <?php if (empty($navCategories)): ?>
                <p class="muted">No favorite categories yet.</p>
            <?php else: ?>
                <?php foreach ($navCategories as $cat): ?>
                    <?php // Keep the current type filter (images/videos) on the category link. ?>
                    <?php $isActive = (int) $cat['id'] === $activeCategoryId; ?>
                    <a class="home-nav-link<?= $isActive ? ' active' : '' ?>" href="<?= url('/galleries/category/' . e($cat['slug']) . $typeSuffix) ?>"><?= e($cat['name']) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>
        </div>
        <main class="home-main">
            <?php require $content; ?>
        </main>
    </div>
    <?php else: ?>
    <?php require $content; ?>
    <?php endif; ?>
    <script>
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

            // Align the sidebar's top edge with the first section (favorite
            // category / gallery type) shown in the right-hand panel, so the
            // nav never sits above the page content.
            function alignSidebar() {
                var wrap = document.querySelector('.home-nav-wrap');
                var main = document.querySelector('.home-main');
                if (!wrap || !main) { return; }

                var first = main.querySelector('.fav-section');
                if (!first) {
                    wrap.style.paddingTop = '';
                    return;
                }

                var offset = first.getBoundingClientRect().top - main.getBoundingClientRect().top;
                wrap.style.paddingTop = Math.max(0, Math.round(offset)) + 'px';
            }
            alignSidebar();
            window.addEventListener('resize', alignSidebar);
            window.addEventListener('load', alignSidebar);
        })();
    </script>
</body>
</html>
