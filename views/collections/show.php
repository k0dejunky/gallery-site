<?php $title = e((string) ($collection['name'] ?? 'Collection')); ?>
<h1><?= e((string) ($collection['name'] ?? 'Collection')) ?></h1>
<p class="muted" style="margin-bottom:1rem;">
    <a href="<?= url('/collections') ?>">&larr; Back to collections</a>
    <?php if (!empty($firstMediaId)): ?>
        &nbsp; &middot; &nbsp;
        <a class="btn btn-sm" href="<?= url(($firstMediaIsVideo ? '/videos/' : '/images/') . (int) $firstMediaId . '?playlist=' . (int) $collection['id']) ?>">&#9654; Play collection</a>
    <?php endif; ?>
</p>

<section class="card" style="padding:1rem;margin-bottom:1.5rem;">
    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/rename') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <label class="muted" style="font-size:.9rem;">Rename</label>
        <input type="text" name="name" required maxlength="120" value="<?= e((string) ($collection['name'] ?? '')) ?>" style="flex:1;min-width:200px;">
        <button type="submit" class="btn btn-sm">Save name</button>
    </form>
</section>

<?php if (empty($galleries) && empty($videos) && empty($images)): ?>
    <div class="empty-state">
        <p>This collection is empty. Add whole galleries from any gallery page, or add individual videos and images from the video player and media pages.</p>
        <a class="btn btn-sm" href="<?= url('/galleries') ?>">Browse galleries</a>
    </div>
<?php else: ?>
    <?php if (!empty($images)): ?>
        <h2 class="section-title">Images</h2>
        <div class="grid">
            <?php foreach ($images as $img): ?>
                <div class="card card-compact">
                    <a class="card-link" href="<?= url('/images/' . (int) $img['id'] . '?playlist=' . (int) ($collection['id'] ?? 0)) ?>">
                        <div class="card-cover"><img src="<?= e((string) $img['thumb']) ?>" alt="" loading="lazy"></div>
                        <div class="card-body">
                            <h3 style="margin:.4rem 0 .15rem;font-size:.95rem;"><?= e($img['caption'] !== '' ? (string) $img['caption'] : 'Image') ?></h3>
                        </div>
                    </a>
                    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/photos/' . (int) $img['id'] . '/delete') ?>" style="padding:.6rem;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline" style="width:100%;">Remove image</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($videos)): ?>
        <h2 class="section-title" style="<?= !empty($images) ? 'margin-top:1.5rem;' : '' ?>">Videos</h2>
        <div class="grid">
            <?php foreach ($videos as $v): ?>
                <div class="card card-compact">
                    <a class="card-link" href="<?= url('/videos/' . (int) $v['id'] . '?playlist=' . (int) ($collection['id'] ?? 0)) ?>">
                        <div class="card-cover"><img src="<?= e((string) $v['thumb']) ?>" alt="" loading="lazy"></div>
                        <div class="card-body">
                            <h3 style="margin:.4rem 0 .15rem;font-size:.95rem;"><?= e($v['caption'] !== '' ? (string) $v['caption'] : 'Video') ?></h3>
                            <p class="muted" style="margin:0;font-size:.85rem;"><?= e(!empty($v['duration_seconds']) ? gmdate('i:s', (int) $v['duration_seconds']) : '') ?></p>
                        </div>
                    </a>
                    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/photos/' . (int) $v['id'] . '/delete') ?>" style="padding:.6rem;">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline" style="width:100%;">Remove video</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($galleries)): ?>
        <h2 class="section-title" style="<?= !empty($videos) ? 'margin-top:1.5rem;' : '' ?>">Galleries</h2>
        <div class="grid">
            <?php foreach ($galleries as $gallery): ?>
                <?php
                $gid = (int) $gallery['id'];
                $cover = $gallery['first_photo'] ?? null;
                $galleryCategories = \App\Models\Gallery::categoriesBulk([$gid])[$gid] ?? [];
                require __DIR__ . '/../partials/gallery_card.php';
                ?>
                <div style="text-align:center;margin-top:-.4rem;margin-bottom:1rem;">
                    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/galleries/' . $gid . '/delete') ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline">Remove from collection</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>