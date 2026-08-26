<?php $title = $photo['caption'] !== '' ? $photo['caption'] : 'Full-size image'; ?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => 'Gallery', 'url' => url('/galleries/' . (int) ($gallery['id'] ?? 0))],
    ['label' => $photo['caption'] ?: 'Image'],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>
<?php $reportUrl = url('/support') . '?' . http_build_query(['return_to' => $_SERVER['REQUEST_URI'] ?? url('/galleries')]); ?>

<figure style="margin: 1rem 0; text-align: center;">
    <p class="media-progress" role="status">Item <?= (int) ($currentIndex + 1) ?> of <?= count($mediaItems ?? [$photo]) ?></p>
    <img id="fullsize-img"
         src="<?= e(file_url($photo['filename'], 'web')) ?>"
         data-web="<?= e(file_url($photo['filename'], 'web')) ?>"
         data-full="<?= e(file_url($photo['filename'])) ?>"
         decoding="async"
         fetchpriority="high"
         alt="<?= e($photo['caption']) ?>"
         style="max-width: 100%; height: auto; border-radius: 10px; box-shadow: 0 2px 12px rgba(59, 7, 100, 0.35);">
    <figcaption class="muted" style="margin-top: 0.75rem">
        <?php if ($photo['caption'] !== ''): ?>
            <span><?= e($photo['caption']) ?></span><br>
        <?php endif; ?>
        <button id="fullsize-toggle" class="btn" type="button">View full size</button>
        <span>&middot; <?= number_format((int) ($photo['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($photo['unique_views'] ?? 0)) ?> unique</span>
    </figcaption>
</figure>
<p style="text-align:center"><a href="<?= e($reportUrl) ?>">Report broken media</a></p>
<script>
    (function () {
        var img = document.getElementById('fullsize-img');
        var toggle = document.getElementById('fullsize-toggle');
        if (!img || !toggle) return;
        var full = false;
        toggle.addEventListener('click', function () {
            full = !full;
            img.src = full ? img.dataset.full : img.dataset.web;
            toggle.textContent = full ? 'Show smaller image' : 'View full size';
        });
    })();
</script>
