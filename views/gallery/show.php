<?php $title = $gallery['title']; ?>
<?php
$galleryDescription = trim((string) ($gallery['description'] ?? ''));
$metaDescription = $galleryDescription !== ''
    ? $galleryDescription
    : 'Browse "' . $title . '" — ' . number_format((int) ($total ?? 0)) . ' items on ' . config('app.site_name') . '.';
$canonicalUrl = absolute_url('/galleries/' . (int) $gallery['id']);
$ogImage = isset($photos[0]['filename']) && $photos[0]['filename'] !== '' ? absolute_url(file_url($photos[0]['filename'], 'thumb')) : '';
$ldJson = [
    '@context' => 'https://schema.org',
    '@type'    => 'CollectionPage',
    'name'     => $title,
    'url'      => $canonicalUrl,
    'description' => $galleryDescription !== '' ? $galleryDescription : $metaDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => config('app.site_name'), 'url' => absolute_url('')],
];
?>
<?php
$breadcrumbItems = [
    ['label' => 'Galleries', 'url' => url('/galleries')],
    ['label' => $gallery['title']],
];
?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>

<h1><?= e($gallery['title']) ?></h1>
<p><?= e($gallery['description']) ?></p>
<p class="muted"><?= number_format((int) ($gallery['views'] ?? 0)) ?> views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers &middot; <?= number_format((int) $total) ?> items</p>

<?php require __DIR__ . '/../partials/star_rating.php'; ?>

<?php if ($ppvPrice > 0 && empty($galleryUnlocked)): ?>
    <div class="settings-form" style="border:1px solid var(--purple-400,#a855f7);border-radius:var(--card-radius,8px);padding:1rem;margin:0 0 1rem;background:var(--pink-100,#fdf4ff);">
        <strong>Premium gallery.</strong> Unlock full access for
        <strong>$<?= e(number_format((float) $ppvPrice, 2)) ?></strong>.
        <?php if (\App\Core\Auth::check()): ?>
            <form method="post" action="<?= url('/galleries/' . (int) $gallery['id'] . '/unlock') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.5rem;">
                <?= csrf_field() ?>
                <input type="text" name="unlock_code" placeholder="Enter your unlock code" style="min-width:220px;">
                <button type="submit" class="btn btn-sm">Unlock</button>
            </form>
            <p class="muted" style="margin:.5rem 0 0;font-size:.85rem;">You&rsquo;ll receive a code after purchase.</p>
        <?php else: ?>
            <p style="margin:.5rem 0 0;"><a class="btn btn-sm" href="<?= url('/signup') ?>">Create an account</a> to purchase and unlock.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($canViewFull && !empty($collections)): ?>
    <form method="post" action="<?= url('/collections/' . (int) $collections[0]['id'] . '/galleries') ?>" class="settings-form" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
        <label class="muted" style="font-size:.9rem;">Save to collection</label>
        <select name="collection_id" onchange="this.form.action='<?= url('/collections') ?>/'+this.value+'/galleries'">
            <?php foreach ($collections as $c): ?>
                <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline">Add to collection</button>
        <a href="<?= url('/collections') ?>" class="muted" style="font-size:.85rem;">Manage collections</a>
    </form>
<?php elseif ($canViewFull): ?>
    <p class="muted" style="font-size:.9rem;margin-bottom:.75rem;">
        <a href="<?= url('/collections') ?>">Create a collection</a> to save this gallery.
    </p>
<?php endif; ?>

<?php if (!empty($categories)): ?>
    <div class="chips">
        <?php foreach ($categories as $cat): ?>
            <a class="chip" href="<?= url('/galleries/category/' . e($cat['slug'])) ?>"><?= e($cat['name']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($tags)): ?>
    <div class="chips">
        <?php foreach ($tags as $tag): ?>
            <a class="chip" href="<?= url('/galleries/tag/' . e($tag['slug'])) ?>">#<?= e($tag['name']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($photos)): ?>
    <div class="empty-state">
        <p class="muted">This gallery doesn&rsquo;t have any media yet.</p>
        <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse other galleries</a>
    </div>
<?php elseif (!$canViewFull): ?>
    <?php // Blurred, indexable preview for guests and below-level members; the
        // full grid and its lightbox stay behind the membership gate. ?>
    <div class="grid">
        <?php foreach ($photos as $idx => $photo): ?>
            <figure class="gallery-item">
                <a class="grid-link" href="<?= e(url((is_video($photo['filename']) ? '/videos/' : '/images/') . (int) $photo['id'])) ?>">
                    <img src="<?= e(file_url($photo['filename'], 'blur')) ?>"
                         alt="<?= e($photo['caption']) ?>" loading="lazy" decoding="async">
                </a>
                <figcaption>
                    <?php if ($photo['link'] !== ''): ?>
                        <a href="<?= e($photo['link']) ?>" rel="noopener"><?= e($photo['caption']) ?></a>
                    <?php else: ?>
                        <?= e($photo['caption']) ?>
                    <?php endif; ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php
    $gateTitle = $gallery['title'];
    $gateLevel = (int) ($gallery['min_level'] ?? 0);
    $gateMedia = 'gallery';
    require __DIR__ . '/../partials/membership_gate.php';
    ?>
<?php else: ?>
    <div class="grid" id="gallery" data-gallery-id="<?= (int) $gallery['id'] ?>"
         data-total="<?= (int) $total ?>" data-loaded="<?= count($photos) ?>"
         data-page-size="<?= (int) $pageSize ?>" data-return-to="<?= e($returnTo) ?>">
        <?php foreach ($photos as $idx => $photo): ?>
            <?php require __DIR__ . '/../partials/gallery_grid_item.php'; ?>
        <?php endforeach; ?>
    </div>
    <?php if ($total > count($photos)): ?>
        <div class="load-more-wrap" id="load-more-wrap">
            <span id="gallery-progress" class="muted" role="status"></span>
            <button class="btn" id="load-more-btn">Load more</button>
            <span id="load-more-state" class="muted" role="status" aria-live="polite"></span>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php
$sharePath  = '/galleries/' . (int) $gallery['id'];
$shareTitle = $gallery['title'];
require __DIR__ . '/../partials/share-bar.php';
?>

<?php if (!empty($related)): ?>
    <section style="margin-top:2rem;">
        <h2>Related galleries</h2>
        <div class="grid">
            <?php foreach ($related as $gallery): ?>
                <?php require __DIR__ . '/../partials/gallery_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section id="comments" style="margin-top:2rem;">
    <h2>Comments (<?= (int) $commentCount ?>)</h2>

    <?php if (empty($comments)): ?>
        <p class="muted">No comments yet.</p>
    <?php else: ?>
        <div style="display:grid;gap:1rem;margin-bottom:1rem;">
            <?php foreach ($comments as $comment): ?>
                <div id="comment-<?= (int) $comment['id'] ?>" style="border-left:3px solid var(--card-border,#ddd);padding-left:.75rem;">
                    <p style="margin:0 0 .15rem;">
                        <strong><?= \App\Models\Comment::isStaff((string) ($comment['role'] ?? '')) ? 'Site team' : 'Member' ?></strong>
                        <span class="muted"> &middot; <?= e(tzdate('M j, g:ia', (string) $comment['created_at'])) ?></span>
                    </p>
                    <p style="margin:0;"><?= e($comment['body']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (\App\Core\Auth::check()): ?>
        <form method="post" action="<?= url('/comments') ?>" style="display:flex;gap:.5rem;align-items:flex-start;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="commentable_type" value="gallery">
            <input type="hidden" name="commentable_id" value="<?= (int) $gallery['id'] ?>">
            <textarea name="body" rows="3" style="flex:1;min-width:220px;" placeholder="Leave a comment… (max 2000 chars)" maxlength="2000"></textarea>
            <button type="submit" class="btn">Post comment</button>
        </form>
    <?php else: ?>
        <p class="muted"><a href="<?= url('/signup') ?>">Create an account</a> or <a href="<?= url('/login') ?>">log in</a> to comment.</p>
    <?php endif; ?>
</section>

<?php if (\App\Core\Auth::check()): ?>
    <section style="margin-top:2rem;border-top:1px solid var(--card-border,#eee);padding-top:1.25rem;">
        <h2>Leave a tip</h2>
        <p class="muted">Show your appreciation — your note goes straight to the studio.</p>
        <form method="post" action="<?= url('/tip') ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" style="width:120px;">
            <input type="text" name="note" placeholder="Add a note" style="flex:1;min-width:200px;" maxlength="500">
            <button type="submit" class="btn btn-outline">Send tip</button>
        </form>
    </section>
<?php endif; ?>
