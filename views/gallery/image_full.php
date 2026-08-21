<?php $title = $photo['caption'] !== '' ? $photo['caption'] : 'Full-size image'; ?>

<?php // Image page inside the site layout: the fast-loading web variant is
     // shown first and a button swaps in the full-size original. Includes
     // Previous/Next navigation through the gallery and a back button. ?>
<?php require __DIR__ . '/../partials/media_nav.php'; ?>

<figure style="margin: 1rem 0; text-align: center;">
    <img id="fullsize-img"
         src="<?= e(file_url($photo['filename'], 'web')) ?>"
         data-web="<?= e(file_url($photo['filename'], 'web')) ?>"
         data-full="<?= e(file_url($photo['filename'])) ?>"
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
<script>
    (function () {
        const img = document.getElementById('fullsize-img');
        const toggle = document.getElementById('fullsize-toggle');
        if (!img || !toggle) {
            return;
        }
        let full = false;
        toggle.addEventListener('click', function () {
            full = !full;
            img.src = full ? img.dataset.full : img.dataset.web;
            toggle.textContent = full ? 'Show smaller image' : 'View full size';
        });
    })();
</script>
