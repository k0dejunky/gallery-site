<?php
/**
 * Gallery display-options toolbar.
 *
 * Preferences are stored in localStorage and applied client-side via CSS
 * classes on the wrapping container.
 *
 * Expected variables from the calling view:
 *   $sort        – current sort key ('', 'views', 'title')
 *   $q           – search query (string)
 *   $type        – type filter ('', 'images', 'videos')
 *   $categoryId  – category ID (int, 0 = none)
 *   $baseUrl     – base URL for sort links (optional, defaults to /galleries)
 */
$sort   = $sort ?? '';
$q      = $q ?? '';
$type   = $type ?? '';
$categoryId = $categoryId ?? 0;
$baseUrl   = $baseUrl ?? url('/galleries');

function _gdSortUrl(string $base, string $sortVal, string $q, string $type, int $categoryId): string {
    $params = [];
    if ($q !== '') $params['q'] = $q;
    if ($categoryId > 0) $params['category'] = $categoryId;
    if ($type !== '') $params['type'] = $type;
    if ($sortVal !== '') $params['sort'] = $sortVal;
    return $params ? $base . '?' . http_build_query($params) : $base;
}
?>
<div class="g-display-bar" id="gDisplayBar">
    <div class="g-display-group">
        <span class="g-display-label">View</span>
        <button class="g-display-btn" data-gd="view" data-val="grid" title="Grid view">&#9783;</button>
        <button class="g-display-btn" data-gd="view" data-val="list" title="List view">&#9776;</button>
        <button class="g-display-btn" data-gd="view" data-val="compact" title="Compact view">&#9638;</button>
    </div>
    <div class="g-display-group">
        <span class="g-display-label">Size</span>
        <button class="g-display-btn" data-gd="size" data-val="sm" title="Small thumbnails">S</button>
        <button class="g-display-btn" data-gd="size" data-val="md" title="Medium thumbnails">M</button>
        <button class="g-display-btn" data-gd="size" data-val="lg" title="Large thumbnails">L</button>
    </div>
    <div class="g-display-group">
        <button class="g-display-btn" data-gd="masonry" data-val="1" title="Masonry layout">Masonry</button>
    </div>
    <div class="g-display-group">
        <span class="g-display-label">Per page</span>
        <button class="g-display-btn" data-gd="perpage" data-val="12">12</button>
        <button class="g-display-btn" data-gd="perpage" data-val="24">24</button>
        <button class="g-display-btn" data-gd="perpage" data-val="48">48</button>
        <button class="g-display-btn" data-gd="perpage" data-val="0">All</button>
    </div>
    <div class="g-display-group g-display-sort">
        <span class="g-display-label">Sort</span>
        <?php foreach (['' => 'Newest', 'views' => 'Most Viewed', 'title' => 'A–Z'] as $val => $label): ?>
            <a class="g-display-btn <?= $sort === $val ? 'active' : '' ?>"
               href="<?= e(_gdSortUrl($baseUrl, $val, $q, $type, $categoryId)) ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
</div>
