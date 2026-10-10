<?php
// Guest landing teaser column: renders the recent pictures OR recent videos
// (based on $guestSide = 'pics'|'videos') as a vertical stack of blurred
// cards. Shared by the login and signup pages, which place the pictures
// column left of the form and the videos column right of it. Expects
// $recentImages, $recentVideos and $guestSide.
$pictureBlank = 'data:image/svg+xml;utf8,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ffd9e8"/><rect x="130" y="102" width="140" height="96" rx="12" fill="none" stroke="#f472b6" stroke-width="8"/><circle cx="185" cy="145" r="14" fill="#ec4899"/><path d="M130 196l42-42 32 30 44-52 52 64" fill="none" stroke="#9333ea" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
);

$guestSide = $guestSide === 'videos' ? 'videos' : 'pics';
$source = $guestSide === 'videos' ? $recentVideos : $recentImages;
$isVideo = $guestSide === 'videos';

$items = [];
foreach ($source as $photo) {
    if ((int) $photo['gallery_id'] <= 0) {
        continue;
    }
    $items[] = [
        'url'      => url(($isVideo ? '/videos/' : '/images/') . (int) $photo['id']),
        'thumb'    => file_url($photo['filename'], 'blur'),
        'filename' => (string) $photo['filename'],
    ];
}
?>
<?php if ($items !== []): ?>
<section class="guest-teaser">
    <div class="guest-grid" data-guest-grid<?= !empty($guestMaxRows) ? ' data-max-rows="' . (int) $guestMaxRows . '"' : '' ?>>
        <?php foreach ($items as $item): ?>
            <div class="card recent-card">
                <a class="card-link" href="<?= e($item['url']) ?>">
                    <div class="card-cover">
                        <?php if (!$isVideo): ?>
                            <picture>
                                <source type="image/webp" srcset="<?= e(file_url($item['filename'], 'blur', 'webp')) ?>">
                                <img src="<?= e($item['thumb']) ?>" alt="" width="400" height="300" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                            </picture>
                        <?php else: ?>
                            <img src="<?= e($item['thumb']) ?>" alt="" width="400" height="300" loading="lazy" onerror="this.onerror=null;this.src='<?= e($pictureBlank) ?>'">
                            <span class="play-badge">&#9654;</span>
                        <?php endif; ?>
                        <span class="card-blur-note">Members-only</span>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<script>
// Fit each teaser grid to whole rows so the "extra" thumbnails are dropped
// cleanly at the page bottom instead of showing a clipped partial row. The
// inline cap is reset first so a re-fit never shrinks from a previous fit.
(function () {
    function fitGrids() {
        document.querySelectorAll('[data-guest-grid]').forEach(function (grid) {
            var tile = grid.querySelector('.recent-card');
            if (!tile) return;
            grid.style.maxHeight = '';
            var cs = getComputedStyle(grid);
            var maxH = parseFloat(cs.maxHeight);
            if (!isFinite(maxH) || maxH <= 0) return;
            var gap = parseFloat(cs.rowGap) || 0;
            // Use the real row pitch (top of the 6th tile minus the 1st, or the
            // tile height + gap as a fallback) — grid auto-rows can be taller
            // than the tile, which otherwise clips the last visible row.
            var rowH = 0;
            if (grid.children.length > 5) {
                var first = grid.children[0].getBoundingClientRect();
                var sixth = grid.children[5].getBoundingClientRect();
                rowH = sixth.top - first.top;
            }
            if (!(rowH > 0)) { rowH = tile.getBoundingClientRect().height + gap; }
            var rows = Math.max(1, Math.floor(maxH / rowH));
            var maxRows = parseInt(grid.getAttribute('data-max-rows'), 10);
            if (isFinite(maxRows) && maxRows > 0) { rows = Math.min(rows, maxRows); }
            grid.style.maxHeight = (rows * rowH - gap) + 'px';
        });
    }
    if (document.readyState !== 'loading') { fitGrids(); }
    else { document.addEventListener('DOMContentLoaded', fitGrids); }
    window.addEventListener('load', fitGrids);
})();
</script>
<?php endif; ?>