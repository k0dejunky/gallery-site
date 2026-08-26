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
$backUrl   = $gallery !== null ? url('/galleries/' . (int) $gallery['id']) : url('/galleries');
$returnTo  = isset($returnTo) && is_string($returnTo) ? $returnTo : $backUrl;
$returnQuery = http_build_query(['return_to' => $returnTo]);
$backLabel = $gallery !== null
    ? '&larr; Back to &ldquo;' . e($gallery['title']) . '&rdquo;'
    : '&larr; Back to galleries';
?>
<div class="media-nav">
    <?php if ($prevUrl !== null): ?>
        <a class="btn" href="<?= e($prevUrl . '?' . $returnQuery) ?>">&larr; Previous</a>
    <?php else: ?>
        <span class="btn btn-disabled" aria-disabled="true">&larr; Previous</span>
    <?php endif; ?>

    <a class="btn btn-outline" href="<?= e($returnTo) ?>"><?= $backLabel ?></a>

    <?php if ($nextUrl !== null): ?>
        <a class="btn" href="<?= e($nextUrl . '?' . $returnQuery) ?>">Next &rarr;</a>
    <?php else: ?>
        <span class="btn btn-disabled" aria-disabled="true">Next &rarr;</span>
    <?php endif; ?>
</div>
