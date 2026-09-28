<?php
// Shared navigation row for the in-page image/video viewers: Previous /
// Back to the gallery / Next. Neighbour links point at each neighbour's own
// viewer (a gallery can contain a mix of images and videos).
$prevUrl = null;
if ($prev !== null) {
    $prevUrl = url('/' . (is_video($prev['filename']) ? 'videos' : 'images') . '/' . (int) $prev['id']);
}
$nextUrl = null;
if ($next !== null) {
    $nextUrl = url('/' . (is_video($next['filename']) ? 'videos' : 'images') . '/' . (int) $next['id']);
}
$gallery   = $gallery ?? null;
$backUrl   = $gallery !== null ? url('/galleries/' . (int) $gallery['id']) : url('/galleries');
$returnTo  = isset($returnTo) && is_string($returnTo) ? $returnTo : $backUrl;
$returnQuery = http_build_query(['return_to' => $returnTo]);
$playlistQuery = isset($playlistQuery) ? (string) $playlistQuery : '';
$backLabel = $gallery !== null
    ? '&larr; Back to &ldquo;' . e($gallery['title']) . '&rdquo;'
    : '&larr; Back to galleries';
// When a gallery runs out of items, offer the neighbouring gallery so the
// viewer is never stuck at a dead end.
$prevGallery = $prevGallery ?? null;
$nextGallery = $nextGallery ?? null;
$prevGalleryUrl = $prevGallery !== null ? url('/galleries/' . (int) $prevGallery['id']) : null;
$nextGalleryUrl = $nextGallery !== null ? url('/galleries/' . (int) $nextGallery['id']) : null;
?>
<div class="media-nav">
    <?php if ($prevUrl !== null): ?>
        <a class="btn" data-swap data-prev="1" href="<?= e($prevUrl . '?' . $returnQuery . $playlistQuery) ?>">&larr; Previous</a>
    <?php elseif ($prevGalleryUrl !== null): ?>
        <a class="btn" href="<?= e($prevGalleryUrl) ?>">&larr; Previous gallery</a>
    <?php else: ?>
        <span class="btn btn-disabled" aria-disabled="true">&larr; Previous</span>
    <?php endif; ?>

    <a class="btn btn-outline" href="<?= e($returnTo) ?>"><?= $backLabel ?></a>

    <?php if ($nextUrl !== null): ?>
        <a class="btn" data-swap data-next="1" href="<?= e($nextUrl . '?' . $returnQuery . $playlistQuery) ?>">Next &rarr;</a>
    <?php elseif ($nextGalleryUrl !== null): ?>
        <a class="btn" href="<?= e($nextGalleryUrl) ?>">Next gallery &rarr;</a>
    <?php else: ?>
        <span class="btn btn-disabled" aria-disabled="true">Next &rarr;</span>
    <?php endif; ?>
</div>
