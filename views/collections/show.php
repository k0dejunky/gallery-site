<?php $title = e((string) ($collection['name'] ?? 'Collection')); ?>
<h1><?= e((string) ($collection['name'] ?? 'Collection')) ?></h1>
<p class="muted" style="margin-bottom:1rem;">
    <a href="<?= url('/collections') ?>">&larr; Back to collections</a>
</p>

<section class="card" style="padding:1rem;margin-bottom:1.5rem;">
    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/rename') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <label class="muted" style="font-size:.9rem;">Rename</label>
        <input type="text" name="name" required maxlength="120" value="<?= e((string) ($collection['name'] ?? '')) ?>" style="flex:1;min-width:200px;">
        <button type="submit" class="btn btn-sm">Save name</button>
    </form>
</section>

<?php if (empty($galleries)): ?>
    <div class="empty-state">
        <p>This collection is empty. Add galleries from any gallery page.</p>
        <a class="btn btn-sm" href="<?= url('/galleries') ?>">Browse galleries</a>
    </div>
<?php else: ?>
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