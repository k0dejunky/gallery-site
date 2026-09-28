<?php $title = 'My collections'; ?>
<h1>My collections</h1>
<p class="muted">Build your own lists of galleries — pick a name and add galleries you love.</p>

<section class="card" style="padding:1rem;margin-bottom:1.5rem;">
    <form method="post" action="<?= url('/collections') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <label class="muted" style="font-size:.9rem;">New collection name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Favourites for later" style="flex:1;min-width:220px;">
        <button type="submit" class="btn">Create collection</button>
    </form>
</section>

<?php if (empty($collections)): ?>
    <div class="empty-state">
        <p>You have no collections yet.</p>
        <a class="btn btn-sm" href="<?= url('/galleries') ?>">Browse galleries</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($collections as $c): ?>
            <div class="card card-compact">
                <a class="card-link" href="<?= url('/collections/' . (int) $c['id']) ?>">
                    <div class="card-cover">
                        <?php if (!empty($c['cover'])): ?>
                            <img src="<?= e((string) $c['cover']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="card-placeholder" style="display:grid;place-items:center;height:100%;color:var(--purple-600);font-size:2.2rem;">&#128215;</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <h3 style="margin:.4rem 0 .15rem;"><?= e((string) $c['name']) ?></h3>
                        <p class="muted" style="margin:0;font-size:.85rem;">
                            <?= (int) $c['gallery_count'] ?> galler<?= (int) $c['gallery_count'] === 1 ? 'y' : 'ies' ?>
                            <?= (int) $c['video_count'] > 0 ? ' &middot; ' . (int) $c['video_count'] . ' video' . ((int) $c['video_count'] === 1 ? '' : 's') : '' ?>
                        </p>
                    </div>
                </a>
                <form method="post" action="<?= url('/collections/' . (int) $c['id'] . '/delete') ?>" onsubmit="return confirm('Delete this collection?');" style="padding:.6rem;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline" style="width:100%;">Delete</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>