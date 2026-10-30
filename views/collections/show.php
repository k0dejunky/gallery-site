<?php
// The layout escapes the title itself; escaping it here too would show a
// collection named "Bob's & Co" as "Bob&#039;s &amp; Co".
$title = (string) ($collection['name'] ?? 'Collection');
$collectionId = (int) ($collection['id'] ?? 0);
$totalSeconds = 0;
foreach ((array) ($videos ?? []) as $v) {
    $totalSeconds += (int) ($v['duration_seconds'] ?? 0);
}
$mediaCount = count((array) ($videos ?? [])) + count((array) ($images ?? [])) + count((array) ($galleries ?? []));

// The list of everything in the collection, as plain URLs, so a member can
// copy it somewhere else.
$collectionList = [];
foreach ((array) ($galleries ?? []) as $g) {
    $collectionList[] = absolute_url('/galleries/' . (int) $g['id']);
}
foreach ((array) ($videos ?? []) as $v) {
    $collectionList[] = absolute_url('/videos/' . (int) $v['id']);
}
foreach ((array) ($images ?? []) as $i) {
    $collectionList[] = absolute_url('/images/' . (int) $i['id']);
}
$collectionList = implode("\n", $collectionList);
?>
<div class="page-shell">
<h1><?= e((string) ($collection['name'] ?? 'Collection')) ?></h1>
<?php if ($mediaCount > 0): ?>
    <?php /* The copy button copies THIS element's value (the copy helper
        copies an element, not a literal). */ ?>
    <textarea id="collection-list-text" readonly hidden aria-hidden="true"><?= e($collectionList) ?></textarea>
<?php endif; ?>
<p class="muted u-mb-1">
    <a href="<?= url('/collections') ?>">&larr; Back to collections</a>
    <?php if ($mediaCount > 0): ?>
        &nbsp; &middot; &nbsp; <?= (int) $mediaCount ?> item<?= $mediaCount === 1 ? '' : 's' ?><?= $totalSeconds > 0 ? ', ' . e(gmdate($totalSeconds >= 3600 ? 'H:i:s' : 'i:s', $totalSeconds)) : '' ?>
    <?php endif; ?>
    <?php if (!empty($firstMediaId)): ?>
        &nbsp; &middot; &nbsp;
        <a class="btn btn-sm" href="<?= url(($firstMediaIsVideo ? '/videos/' : '/images/') . (int) $firstMediaId . '?playlist=' . $collectionId) ?>">&#9654; Play collection</a>
    <?php endif; ?>
    <?php if ($mediaCount > 0): ?>
        &nbsp; &middot; &nbsp;
        <?php /* A member who wants the set somewhere else needs the list. */ ?>
        <button type="button" class="btn btn-sm btn-outline" data-copy="collection-list-text" data-copy-label="Copy list">Copy the list</button>
    <?php endif; ?>
</p>

<?php /* The private share link: somebody else can see the set without an
      account, and the owner can kill the link. */ ?>
<?php $shareUrl = !empty($shareToken) ? url('/collections/' . (int) ($collection['id'] ?? 0) . '/s/' . $shareToken) : ''; ?>
<section class="card collection-panel">
    <h2>Share this collection</h2>
    <?php if ($shareUrl !== ''): ?>
        <div class="collection-panel-row">
            <input type="text" id="collection-share-url" readonly value="<?= e($shareUrl) ?>" aria-label="The share link">
            <button type="button" class="btn btn-sm" data-copy="collection-share-url" data-copy-label="Copy link">Copy the link</button>
            <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/stop-sharing') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-sm btn-outline" data-confirm="Stop sharing? The link will stop working for everybody.">Stop sharing</button>
            </form>
        </div>
        <p class="muted u-fs-085 collection-panel-note">Anyone with this link can see the collection. Nobody has to sign in.</p>
    <?php else: ?>
        <p class="muted">This collection is not shared.</p>
    <?php endif; ?>
</section>

<section class="card collection-panel">
    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/rename') ?>" class="settings-form collection-panel-row">
        <?= csrf_field() ?>
        <label class="muted u-fs-09">Rename</label>
        <input type="text" name="name" required maxlength="120" value="<?= e((string) ($collection['name'] ?? '')) ?>" aria-label="Collection name">
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
        <div class="section-head"><h2>Images</h2></div>
        <div class="grid">
            <?php foreach ($images as $img): ?>
                <div class="card card-compact">
                    <a class="card-link" href="<?= url('/images/' . (int) $img['id'] . '?playlist=' . (int) ($collection['id'] ?? 0)) ?>">
                        <div class="card-cover"><img src="<?= e((string) $img['thumb']) ?>" alt="" loading="lazy"></div>
                        <div class="card-body">
                            <h3 class="collection-media-title"><?= e($img['caption'] !== '' ? (string) $img['caption'] : 'Image') ?></h3>
                        </div>
                    </a>
                    <form method="post" action="<?= url('/collections/' . $collectionId . '/move') ?>" class="collection-order collection-media-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="photo">
                        <input type="hidden" name="photo_id" value="<?= (int) $img['id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="btn btn-sm btn-outline" title="Move up" aria-label="Move <?= e($img['caption'] !== '' ? (string) $img['caption'] : 'this image') ?> up">&#9650; Up</button>
                        <button type="submit" class="btn btn-sm btn-outline" name="dir" value="down" title="Move down" aria-label="Move <?= e($img['caption'] !== '' ? (string) $img['caption'] : 'this image') ?> down">&#9660; Down</button>
                    </form>
                    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/photos/' . (int) $img['id'] . '/delete') ?>" class="collection-media-remove">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline u-w-full">Remove image</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($videos)): ?>
        <div class="section-head<?= !empty($images) ? ' collection-section-gap' : '' ?>"><h2>Videos</h2></div>
        <div class="grid">
            <?php foreach ($videos as $v): ?>
                <div class="card card-compact">
                    <a class="card-link" href="<?= url('/videos/' . (int) $v['id'] . '?playlist=' . (int) ($collection['id'] ?? 0)) ?>">
                        <div class="card-cover"><img src="<?= e((string) $v['thumb']) ?>" alt="" loading="lazy"></div>
                        <div class="card-body">
                            <h3 class="collection-media-title"><?= e($v['caption'] !== '' ? (string) $v['caption'] : 'Video') ?></h3>
                            <p class="muted collection-media-duration"><?= e(!empty($v['duration_seconds']) ? gmdate('i:s', (int) $v['duration_seconds']) : '') ?></p>
                        </div>
                    </a>
                    <form method="post" action="<?= url('/collections/' . $collectionId . '/move') ?>" class="collection-order collection-media-actions-full">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="photo">
                        <input type="hidden" name="photo_id" value="<?= (int) $v['id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="btn btn-sm btn-outline" title="Move up in the playlist" aria-label="Move <?= e($v['caption'] !== '' ? (string) $v['caption'] : 'this video') ?> up">&#9650; Up</button>
                        <button type="submit" class="btn btn-sm btn-outline" name="dir" value="down" title="Move down in the playlist" aria-label="Move <?= e($v['caption'] !== '' ? (string) $v['caption'] : 'this video') ?> down">&#9660; Down</button>
                    </form>
                    <form method="post" action="<?= url('/collections/' . $collectionId . '/photos/' . (int) $v['id'] . '/delete') ?>" class="collection-media-remove-tight">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline u-w-full">Remove video</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($galleries)): ?>
        <div class="section-head<?= (!empty($videos) || !empty($images)) ? ' collection-section-gap' : '' ?>"><h2>Galleries</h2></div>
        <div class="grid">
            <?php foreach ($galleries as $gallery): ?>
                <div class="collection-gallery-cell">
                    <?php
                    $gid = (int) $gallery['id'];
                    $cover = $cardCovers['covers'][$gid] ?? null;
                    $galleryCategories = $cardCovers['categories'][$gid] ?? [];
                    $coverFetchPriority = empty($coverFetchPriority) ? 'high' : '';
                    require __DIR__ . '/../partials/gallery_card.php';
                    ?>
                    <form method="post" action="<?= url('/collections/' . $collectionId . '/move') ?>" class="collection-order collection-media-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="kind" value="gallery">
                        <input type="hidden" name="gallery_id" value="<?= $gid ?>">
                        <input type="hidden" name="dir" value="up">
                        <button type="submit" class="btn btn-sm btn-outline" title="Move up" aria-label="Move <?= e((string) ($gallery['title'] ?? 'this gallery')) ?> up">&#9650; Up</button>
                        <button type="submit" class="btn btn-sm btn-outline" name="dir" value="down" title="Move down" aria-label="Move <?= e((string) ($gallery['title'] ?? 'this gallery')) ?> down">&#9660; Down</button>
                    </form>
                    <form method="post" action="<?= url('/collections/' . (int) ($collection['id'] ?? 0) . '/galleries/' . $gid . '/delete') ?>" class="collection-media-remove">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline u-w-full">Remove from collection</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
