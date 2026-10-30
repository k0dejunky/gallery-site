<?php $title = 'My collections'; ?>
<div class="page-shell">
<h1>My collections</h1>
<p class="muted">Build your own lists of galleries — pick a name and add galleries you love.</p>

<section class="card collection-create">
    <form method="post" action="<?= url('/collections') ?>" class="settings-form">
        <?= csrf_field() ?>
        <label class="muted u-fs-09">New collection name</label>
        <input type="text" name="name" required maxlength="120" placeholder="e.g. Favourites for later" aria-label="Collection name">
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
            <div class="card card-compact collection-card">
                <a class="card-link" href="<?= url('/collections/' . (int) $c['id']) ?>">
                    <div class="card-cover">
                        <?php if (!empty($c['cover'])): ?>
                            <img src="<?= e((string) $c['cover']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="card-placeholder collection-placeholder">&#128215;</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <h3><?= e((string) $c['name']) ?></h3>
                        <p class="muted collection-card-count">
                            <?= (int) $c['gallery_count'] ?> galler<?= (int) $c['gallery_count'] === 1 ? 'y' : 'ies' ?>
                            <?= (int) $c['video_count'] > 0 ? ' &middot; ' . (int) $c['video_count'] . ' video' . ((int) $c['video_count'] === 1 ? '' : 's') : '' ?>
                        </p>
                    </div>
                </a>
                <div class="collection-card-actions">
                    <?php if (!empty($c['play_url'])): ?>
                        <a class="btn btn-sm" href="<?= e($c['play_url']) ?>">&#9654; Play</a>
                    <?php endif; ?>
                    <form method="post" action="<?= url('/collections/' . (int) $c['id'] . '/delete') ?>" data-confirm="Delete this collection?" class="u-m0">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
