<?php
/**
 * A collection, seen through its private share link.
 *
 * Expects:
 *   $collection  array   the collection row
 *   $galleries   list     the galleries in it
 *   $media       list     the media in it
 *   $cardCovers  array    the preloaded cover/category data
 */
$title = 'A shared collection';
?>
<div class="favorites-hero">
    <p class="eyebrow">A shared collection</p>
    <h1><?= e((string) ($collection['name'] ?? 'Collection')) ?></h1>
    <p class="muted">
        <?= count((array) ($galleries ?? [])) ?> gallery(s) &middot;
        <?= count((array) ($media ?? [])) ?> item(s)
    </p>
</div>

<section class="favorites-section">
    <?php if (empty($media) && empty($galleries)): ?>
        <div class="empty-state">
            <p class="muted">This collection is empty.</p>
        </div>
    <?php else: ?>
        <?php if (!empty($media)): ?>
            <h2 class="section-title">Items</h2>
            <div class="grid">
                <?php foreach ((array) $media as $item): ?>
                    <div class="card card-compact">
                        <a class="card-link" href="<?= e(url((is_video((string) $item['filename']) ? '/videos/' : '/images/') . (int) $item['id'])) ?>">
                            <div class="card-cover"><img src="<?= e((string) $item['thumb']) ?>" alt="" loading="lazy"></div>
                            <div class="card-body">
                                <h3 style="margin:.4rem 0 .15rem;font-size:.95rem;">
                                    <?= e($item['caption'] !== '' ? (string) $item['caption'] : 'Item ' . (int) $item['id']) ?>
                                </h3>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($galleries)): ?>
            <h2 class="section-title u-mt-15">Galleries</h2>
            <div class="grid">
                <?php foreach ((array) $galleries as $gallery): ?>
                    <?php
                    $galleryRow = $gallery;
                    $cover = $cardCovers['covers'][(int) $gallery['id']] ?? null;
                    $galleryCategories = $cardCovers['categories'][(int) $gallery['id']] ?? [];
                    require __DIR__ . '/../partials/gallery_card.php';
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
