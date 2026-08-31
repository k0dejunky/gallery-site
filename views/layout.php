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
if ($user !== null) {
    $activeSubscription = \App\Models\Subscription::activeFor((int) $user['id']);
    if ($activeSubscription !== null && in_array($activeSubscription['billing_cycle'] ?? '', ['yearly', 'lifetime'], true)) {
        $userThemePreset = $user['theme_preset'] ?? null;
    }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($title) ? e($title) . ' — ' . config('app.site_name') : e(config('app.site_name')) ?></title>
    <style>
<?= \App\Models\Theme::cssUser($userThemePreset) ?>
<?= \App\Models\Theme::cssLayoutUser($userThemePreset) ?>
        body { font-family: sans-serif; max-width: none; width: 100%; margin: 0; padding: 1rem 1.5rem; box-sizing: border-box; color: var(--purple-900); background: var(--pink-200); font-size: var(--font-size-base); line-height: var(--line-height); }
        h1 { font-size: var(--font-size-h1); }
        h2 { font-size: var(--font-size-xl); }
        h3, h4, h5, h6 { font-size: var(--font-size-lg); }
        h1, h2, h3, h4, h5, h6 { text-align: center; }
        a { color: var(--purple-600); }
        .nav { display: flex; gap: var(--spacing-md); align-items: center; border-bottom: 1px solid var(--pink-300); padding-bottom: var(--spacing-sm); margin-bottom: var(--spacing-lg); }
        .nav a { text-decoration: none; color: var(--purple-700); }
        .nav .brand { font-weight: bold; font-size: var(--font-size-lg); color: var(--purple-900); }
        .nav .spacer { flex: 1; }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], textarea, select { padding: var(--input-padding); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--input-radius); background: var(--pink-100); color: var(--purple-900); }
        input:focus, textarea:focus, select:focus { outline: 2px solid var(--purple-400); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: var(--spacing-md); margin-top: var(--spacing-md); }
        .grid img { width: 100%; height: 150px; object-fit: cover; border-radius: var(--border-radius-sm); cursor: pointer; }
        .grid video { width: 100%; height: auto; object-fit: contain; border-radius: var(--border-radius-sm); background: #000; display: block; cursor: pointer; }
        .video-open { position: relative; display: block; }
        .play-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: rgba(255, 255, 255, 0.9); text-shadow: 0 0 12px rgba(0, 0, 0, 0.7); pointer-events: none; }
        .title-header { text-align: center; margin-bottom: var(--spacing-lg); }
        .title-header img { max-width: 100%; height: auto; border-radius: var(--border-radius-lg); box-shadow: var(--shadow); }
        .card { border: 1px solid var(--card-border); border-radius: var(--card-radius); padding: var(--card-padding); margin-bottom: var(--spacing-md); background: var(--card-bg); box-shadow: var(--shadow); }
        .card a { text-decoration: none; color: inherit; }
        .card-link { display: block; }
        .card img, .card-placeholder { width: 100%; height: 180px; object-fit: cover; border-radius: var(--border-radius-sm); display: block; background: var(--card-placeholder-bg); }
        .card-cover { position: relative; }
        .card-cover .video-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: rgba(255, 255, 255, 0.9); text-shadow: 0 0 12px rgba(0, 0, 0, 0.7); pointer-events: none; }
        .card h2 { margin: var(--spacing-sm) 0 var(--spacing-xs); font-size: var(--font-size-lg); color: var(--card-title-color); }
        .btn { display: inline-block; padding: var(--btn-padding); background: var(--btn-bg); color: var(--btn-color); text-decoration: none; border-radius: var(--btn-radius); border: none; cursor: pointer; font-size: var(--btn-font-size); }
        .btn:hover { background: var(--btn-hover-bg); }
        .btn-danger { background: var(--btn-danger-bg); color: var(--btn-danger-color); border: 1px solid var(--btn-danger-border); }
        .btn-danger:hover { background: var(--btn-danger-hover-bg); }
        .btn-sm { padding: var(--spacing-xs) var(--spacing-sm); font-size: var(--font-size-sm); }
        .btn-outline { background: var(--pink-100); color: var(--purple-700); border: 1px solid var(--pink-400); }
        .btn-outline:hover { background: var(--pink-200); }
        form.inline { display: inline; }
        table { border-collapse: collapse; width: 100%; background: var(--table-bg); border-radius: var(--table-radius); }
        th, td { border: var(--input-border-width) solid var(--table-border); padding: var(--spacing-sm); text-align: left; vertical-align: middle; color: var(--table-text); }
        th { background: var(--table-header-bg); color: var(--table-header-color); }
        .flash { padding: var(--card-padding) var(--spacing-md); border-radius: var(--border-radius-sm); margin-bottom: var(--spacing-md); color: var(--purple-900); }
        .flash.error { background: var(--pink-300); border-left: 4px solid var(--btn-danger-border); }
        .flash.success { background: var(--pink-200); border-left: 4px solid var(--purple-500); }
        .pagination { display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-lg); flex-wrap: wrap; }
        .pagination a, .pagination span { padding: var(--chip-padding); border: var(--input-border-width) solid var(--pagination-border); border-radius: var(--btn-radius); text-decoration: none; color: var(--pagination-color); background: var(--pagination-bg); }
        .pagination a:hover { background: var(--pagination-hover-bg); }
        .pagination .current { background: var(--pagination-active-bg); color: var(--pagination-active-color); border-color: var(--pagination-border); }
        .grid-link { display: block; }
        .media-nav { display: flex; gap: var(--spacing-md); align-items: center; justify-content: center; flex-wrap: wrap; margin: var(--spacing-md) 0; }
        .btn-disabled { opacity: 0.5; cursor: not-allowed; }
        .hero { padding: var(--spacing-md) 0 var(--spacing-lg); text-align: center; }
        .hero form { display: flex; justify-content: center; gap: var(--spacing-sm); flex-wrap: wrap; }
        .auth-panel { max-width: 420px; margin: var(--spacing-xl) auto; padding: var(--spacing-lg); background: var(--pink-100); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--border-radius-lg); }
        .auth-panel h1 { text-align: center; margin-top: 0; color: var(--purple-800); }
        .auth-panel .auth-links { text-align: center; margin-bottom: 0; }
        .auth-panel input[type="email"], .auth-panel input[type="password"] { width: 100%; box-sizing: border-box; }
        .hero input[type="text"] { padding: 0.7rem 1rem; font-size: var(--font-size-base); border: 2px solid var(--purple-400); border-radius: var(--border-radius); width: 60%; min-width: 220px; }
        .chips { display: flex; flex-wrap: wrap; gap: var(--spacing-sm); margin: var(--spacing-md) 0; align-items: center; }
        .chip { display: inline-flex; align-items: center; gap: var(--spacing-xs); padding: var(--chip-padding); border: var(--input-border-width) solid var(--pink-400); border-radius: var(--chip-radius); background: var(--pink-300); color: var(--purple-700); text-decoration: none; }
        .chip a { text-decoration: none; color: inherit; }
        .chip .star { background: none; border: none; cursor: pointer; font-size: var(--font-size-sm); line-height: 1; padding: 0; color: inherit; }
        .chip .star:hover { color: var(--purple-500); }
        .chip:hover { background: var(--filter-hover-bg); }
        .chip.fav { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .chip.fav .star { color: var(--purple-900); }
        .chip.active { background: var(--filter-bg); border-color: var(--filter-border); color: var(--filter-color); }
        .chip.active:hover { background: var(--filter-hover-bg); }
        .chip .star { color: var(--purple-500); font-size: var(--font-size-sm); cursor: pointer; }
        .chip.active .star { color: var(--purple-900); }
        .chip:has(input[type="checkbox"]) { cursor: pointer; }
        .chip input[type="checkbox"] { display: none; }
        .chip:has(input[type="checkbox"]:checked) { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .favorite-option.selected { background: var(--purple-300); border-color: var(--purple-400); color: var(--purple-900); }
        .fav-section { margin-top: var(--spacing-xl); }
        .fav-section h2 { color: var(--purple-800); border-bottom: 2px solid var(--pink-300); padding-bottom: var(--spacing-xs); }
        .fav-section h2 a { font-size: var(--font-size-sm); font-weight: normal; color: var(--purple-600); text-decoration: none; float: right; }
        .section-title { color: var(--purple-800); border-bottom: 2px solid var(--pink-300); padding-bottom: var(--spacing-xs); }
        .muted { color: var(--purple-800); opacity: 0.75; }
        .home-layout { display: flex; gap: var(--spacing-lg); align-items: flex-start; }
         .home-nav-wrap { flex: 0 0 230px; display: flex; flex-direction: column; gap: var(--spacing-md); position: sticky; top: 0; }
        .home-nav-actions, .home-nav { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--spacing-md); background: var(--sidebar-bg); border: var(--input-border-width) solid var(--sidebar-border); border-radius: var(--border-radius-lg); }
        .home-nav-actions { gap: var(--spacing-sm); }
        .home-nav-actions .btn { display: block; width: 100%; box-sizing: border-box; padding: var(--spacing-sm) var(--card-padding); border-radius: var(--border-radius); text-align: center; }
        .home-nav-actions form { margin: 0; }
        .user-nav .nav-brand { font-weight: bold; font-size: var(--font-size-lg); color: var(--sidebar-heading); text-decoration: none; margin: 0 0 var(--spacing-sm); }
        .user-nav .nav-item { display: block; padding: var(--spacing-sm) var(--card-padding); border-radius: var(--border-radius); color: var(--sidebar-link-color); text-decoration: none; background: var(--sidebar-link-bg); border: var(--input-border-width) solid var(--sidebar-link-border); }
        .user-nav .nav-item:hover { background: var(--sidebar-link-hover); }
        .user-nav .nav-item.active { background: var(--sidebar-active-bg); border-color: var(--sidebar-active-border); color: var(--sidebar-active-color); }
        .user-nav .nav-sep { border-top: var(--input-border-width) solid var(--sidebar-border); margin: var(--spacing-sm) 0; }
        .user-nav .nav-section-label { margin: 0 0 var(--spacing-xs); color: var(--sidebar-heading); font-size: var(--font-size-xs); font-weight: bold; text-transform: uppercase; letter-spacing: .05em; }
        .user-nav .nav-empty { margin: 0; font-size: var(--font-size-sm); }
        .user-nav .nav-media-item { display: flex; align-items: center; gap: .5rem; }
        .user-nav .nav-media-item img { width: 40px; height: 30px; flex: 0 0 40px; object-fit: cover; border-radius: 4px; }
        .user-nav .nav-logout { width: 100%; margin-top: var(--spacing-xs); }
        .home-nav-actions h2 { margin: 0 0 var(--spacing-xs); font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--sidebar-heading); text-align: center; }
        .home-nav h2 { margin: 0 0 var(--spacing-sm); font-size: var(--font-size-xs); text-transform: uppercase; letter-spacing: 0.05em; color: var(--sidebar-heading); text-align: center; }
        .home-nav-link { display: block; padding: var(--spacing-sm) var(--card-padding); border-radius: var(--border-radius); color: var(--sidebar-link-color); text-decoration: none; background: var(--sidebar-link-bg); border: var(--input-border-width) solid var(--sidebar-link-border); }
        .home-nav-link:hover { background: var(--sidebar-link-hover); }
        .home-nav-link.active { background: var(--sidebar-active-bg); color: var(--sidebar-active-color); border-color: var(--sidebar-active-border); font-weight: bold; }
        .home-nav-link.active:hover { background: var(--sidebar-active-bg); }
        .card-cats { display: flex; flex-wrap: wrap; gap: var(--spacing-xs); margin-top: var(--spacing-sm); overflow: hidden; }
        .card-cats-toggle { display: block; margin-top: var(--spacing-sm); padding: var(--spacing-xs) 0; background: none; border: none; color: var(--card-cat-link-color); text-decoration: underline; cursor: pointer; font-size: var(--font-size-sm); }
        .card-cats-toggle[hidden] { display: none; }
        .home-main { flex: 1 1 auto; width: 0; min-width: 0; }
         @media (max-width: 768px) { .home-layout { flex-direction: column; } .home-nav-wrap { flex: none; width: 100%; padding-top: 0 !important; position: static; } .home-main { width: 100%; } }
        .recent-card { cursor: pointer; }
        .recent-strip { display: flex; flex-wrap: nowrap; gap: var(--spacing-md); margin-top: var(--spacing-md); overflow: hidden; justify-content: space-between; }
         .recent-strip .card { width: 220px; flex: 0 0 auto; box-sizing: border-box; }
         .theme-choice-layout { display: grid; grid-template-columns: minmax(220px, 0.8fr) minmax(0, 1.4fr); gap: var(--spacing-lg); align-items: start; }
         .theme-choice-list { display: grid; gap: var(--spacing-sm); }
         .theme-choice-card { display: flex; flex-direction: column; gap: var(--spacing-xs); padding: var(--card-padding); border: var(--input-border-width) solid var(--pink-300); border-radius: var(--card-radius); background: var(--pink-100); color: var(--purple-900); cursor: pointer; }
         .theme-choice-card:hover, .theme-choice-card.selected { border-color: var(--purple-400); background: var(--pink-200); }
         .theme-choice-card input { position: absolute; opacity: 0; pointer-events: none; }
         .theme-choice-card span { font-size: var(--font-size-xs); color: var(--purple-700); }
         .theme-swatches { display: flex; gap: 3px; margin-top: var(--spacing-xs); }
         .theme-swatches i { width: 18px; height: 18px; border-radius: 50%; border: 1px solid var(--pink-400); }
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
        .collapsible { overflow: hidden; transition: max-height .3s; }
        img { user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }
     </style>
    <link rel="stylesheet" href="<?= url('/assets/css/user.css') ?>?v=4">
</head>
<body>
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
            <a class="btn btn-sm btn-outline" href="<?= url('/settings') ?>">Settings</a>
            <form class="inline" method="post" action="<?= url('/logout') ?>">
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
        <nav class="home-nav-actions user-nav" aria-label="Site menu">
            <a class="nav-brand" href="<?= url('/account') ?>">Dashboard</a>
            <a class="nav-item<?= $currentPath === url('/account') ? ' active' : '' ?>" href="<?= url('/account') ?>">Dashboard</a>
            <a class="nav-item<?= strpos($currentPath, url('/galleries')) === 0 ? ' active' : '' ?>" href="<?= url('/galleries') ?>">Galleries</a>
            <a class="nav-item<?= strpos($currentPath, url('/favorites')) === 0 ? ' active' : '' ?>" href="<?= url('/favorites') ?>">Favorites</a>
            <a class="nav-item<?= strpos($currentPath, url('/membership')) === 0 ? ' active' : '' ?>" href="<?= url('/membership') ?>">Membership</a>
            <a class="nav-item<?= strpos($currentPath, url('/support')) === 0 ? ' active' : '' ?>" href="<?= url('/support') ?>">Support<?php if (!empty($supportUnreadCount)): ?> <span class="nav-unread" aria-label="<?= (int) $supportUnreadCount ?> unread replies"><?= (int) $supportUnreadCount ?></span><?php endif; ?></a>
            <?php if ($user !== null && \App\Core\Auth::isAdmin()): ?>
                <a class="nav-item" href="<?= url('/admin') ?>">Admin</a>
            <?php endif; ?>
            <a class="nav-item<?= strpos($currentPath, url('/settings')) === 0 ? ' active' : '' ?>" href="<?= url('/settings') ?>">Settings</a>
            <form class="nav-logout" method="post" action="<?= url('/logout') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-danger">Logout</button>
            </form>
            <div class="nav-sep"></div>
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
        </nav>
        </div>
        <main class="home-main">
            <?php require $content; ?>
        </main>
    </div>
    <?php else: ?>
    <?php require $content; ?>
    <?php endif; ?>
    <?php if ($sidebarNav && $user !== null): ?>
    <nav class="media-bottom-nav" aria-label="Mobile site menu">
        <a href="<?= url('/account') ?>">Dashboard</a>
        <a href="<?= url('/galleries') ?>">Galleries</a>
        <a href="<?= url('/favorites') ?>">Favorites</a>
        <a href="<?= url('/membership') ?>">Membership</a>
         <a href="<?= url('/support') ?>">Support<?php if (!empty($supportUnreadCount)): ?> <span class="nav-unread"><?= (int) $supportUnreadCount ?></span><?php endif; ?></a>
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

        })();
    </script>
    <script>
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
    <script>
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
    <script>
    (function(){
        var changes=<?= $_tplJson ?>;
        function applyOrder(c){
            var p=c.parentKey==='body'?document.body:null;
            if(c.parentOrigin){p=document.querySelector(c.parentOrigin)||p;if(p&&c.parentKey)p.setAttribute('data-se-move-key',c.parentKey);}
            if(!p)return;
            (c.items||[]).map(function(item){var el=item.origin?document.querySelector(item.origin):null;if(!el)el=document.querySelector('[data-se-move-key="'+item.key+'"]');if(el&&item.key)el.setAttribute('data-se-move-key',item.key);if(el&&item.styles)Object.keys(item.styles).forEach(function(k){if(item.styles[k])el.style.setProperty(k,item.styles[k]);});return el;}).filter(Boolean).forEach(function(el){p.appendChild(el);});
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
    <script>try{var p=JSON.parse(localStorage.getItem('galleryDisplayPrefs')||'{}');var v=p.view||'grid';var s=p.size||'md';document.documentElement.classList.add('g-view-'+v);document.documentElement.classList.add('g-size-'+s);if(p.masonry)document.documentElement.classList.add('g-masonry');}catch(e){document.documentElement.classList.add('g-view-grid');document.documentElement.classList.add('g-size-md');}</script>
    <script src="<?= url('/assets/js/user.js') ?>?v=4" defer></script>
</body>
</html>
