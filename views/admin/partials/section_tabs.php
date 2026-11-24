<?php
/**
 * Admin hub tabs.
 *
 * Include at the top of a hub page:
 *   $sectionHub = 'chat';
 *   require __DIR__ . '/partials/section_tabs.php';
 *
 * The hub map lives in App\Core\AdminSections, so the sidebar and the tab
 * strip can never disagree. A tab the signed-in admin may not open is not
 * rendered, so a moderator never sees a link that bounces them off a 403.
 */
$sectionHub   = (string) ($sectionHub ?? '');
$sectionTabs  = $sectionHub !== '' ? \App\Core\AdminSections::tabs($sectionHub) : [];
$sectionLabel = $sectionHub !== ''
    ? (string) (\App\Core\AdminSections::hubs()[$sectionHub]['label'] ?? '')
    : '';
$sectionPath  = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');

if ($sectionTabs !== []):
?>
<nav class="adm-tabs" aria-label="<?= e($sectionLabel !== '' ? $sectionLabel . ' sections' : 'Section') ?>">
    <?php foreach ($sectionTabs as $tab): ?>
        <?php
        $tabHref   = url($tab['href']);
        $tabActive = $sectionPath === rtrim($tabHref, '/')
            || strpos($sectionPath . '/', rtrim($tabHref, '/') . '/') === 0;
        ?>
        <a class="adm-tab<?= $tabActive ? ' active' : '' ?>" href="<?= e($tabHref) ?>"<?= $tabActive ? ' aria-current="page"' : '' ?>><?= e($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
