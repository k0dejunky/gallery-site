<?php $title = 'Wall'; ?>

<?php /* The feed is ONE timeline (see WallController::buildFeed): the studio's
    updates and the member's own notifications, newest first, each with its
    own type badge. It used to be two blocks - a collapsible inbox above a
    post list - so a member with 30 notifications had to scroll past all of
    them to reach the studio's news, and the two lists looked like two
    different products. */ ?>
<?php $items = $items ?? []; ?>

<header class="wall-hero">
    <div class="wall-hero-text">
        <p class="eyebrow">From the creator</p>
        <h1>The Wall</h1>
        <p class="muted">New sets and studio updates, right as they land.</p>
    </div>
    <?php if (!empty($unreadIds)): ?>
        <form method="post" action="<?= url('/notifications/read-all') ?>" class="wall-hero-actions">
            <?= csrf_field() ?>
            <span class="wall-unread"><?= count($unreadIds) ?> new</span>
            <button type="submit" class="btn btn-sm btn-outline">Mark all as read</button>
        </form>
    <?php endif; ?>
</header>

<?php
/* The landing rails: where you left off, what is new, and more like what you
   were watching. They sit ABOVE the feed because they are the shortest path
   back into the library, and the feed is the news. */
$rails = $rails ?? [];
$railCards = static function (array $galleries, array $railsData, string $returnTo): void {
    foreach ($galleries as $gallery) {
        $cover              = $railsData['covers'][(int) $gallery['id']] ?? null;
        $galleryCategories  = $railsData['categories'][(int) $gallery['id']] ?? [];
        $viewedIds          = $railsData['viewedIds'];
        $favoriteGalleryIds = $railsData['favoriteGalleryIds'];
        $listingReturnTo    = $returnTo;
        echo '<div class="wall-rail-item">';
        require __DIR__ . '/partials/gallery_card.php';
        echo '</div>';
    }
};
?>
<?php if (!empty($rails['continue']) || !empty($rails['new']) || !empty($rails['like'])): ?>
<section class="wall-rails" aria-label="Back to the library">
    <?php if (!empty($rails['continue'])): ?>
        <div class="wall-rail" role="region" aria-labelledby="rail-continue">
            <div class="wall-rail-head">
                <h2 id="rail-continue">Continue watching</h2>
                <a class="section-link" href="<?= e(url('/account')) ?>">All activity</a>
            </div>
            <div class="wall-rail-scroll">
                <?php foreach ($rails['continue'] as $cw): ?>
                    <?php
                    $position = (float) ($cw['position_seconds'] ?? 0);
                    $total    = (float) ($cw['duration_seconds'] ?? 0);
                    $percent  = $total > 0 ? max(1, min(100, (int) round($position / $total * 100))) : 0;
                    $label    = trim((string) ($cw['caption'] ?? '')) !== '' ? (string) $cw['caption'] : 'Video';
                    // A video's "thumb" is not always generated; its poster
                    // frame is, and it is the right crop for a 16:9 card.
                    $cwImage  = video_poster_url((string) ($cw['filename'] ?? ''));
                    if ($cwImage === '') {
                        $cwImage = file_url((string) ($cw['filename'] ?? ''), 'thumb');
                    }
                    ?>
                    <a class="wall-cw" href="<?= e(url('/videos/' . (int) $cw['photo_id'] . '?t=' . (int) $position)) ?>">
                        <span class="wall-cw-media">
                            <img src="<?= e($cwImage) ?>" alt="" loading="lazy" decoding="async" data-hide-on-error>
                            <span class="wall-cw-progress" aria-hidden="true"><i style="width: <?= $percent ?>%"></i></span>
                            <span class="wall-cw-play" aria-hidden="true">&#9654;</span>
                        </span>
                        <span class="wall-cw-title"><?= e($label) ?></span>
                        <span class="wall-cw-meta"><?= $percent ?>% watched &middot; <?= e(gmdate('i:s', (int) $position)) ?> in</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($rails['new'])): ?>
        <div class="wall-rail" role="region" aria-labelledby="rail-new">
            <div class="wall-rail-head">
                <h2 id="rail-new">New this week</h2>
                <a class="section-link" href="<?= e(url('/galleries')) ?>">Browse all</a>
            </div>
            <div class="wall-rail-scroll">
                <?php $railCards($rails['new'], $rails, '/wall'); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($rails['like'])): ?>
        <div class="wall-rail" role="region" aria-labelledby="rail-like">
            <div class="wall-rail-head">
                <h2 id="rail-like"><?= $rails['likeSource'] !== '' ? 'More like ' . e(mb_substr($rails['likeSource'], 0, 40)) : 'More to explore' ?></h2>
                <a class="section-link" href="<?= e(url('/galleries')) ?>">Browse all</a>
            </div>
            <div class="wall-rail-scroll">
                <?php $railCards($rails['like'], $rails, '/wall'); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($items === []): ?>
    <section class="favorites-section">
        <div class="empty-state">
            <p>Nothing new yet — the wall fills up as soon as the studio posts.</p>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse galleries</a>
        </div>
    </section>
<?php else: ?>
    <?php
    /* One date heading per day, not one per post: the feed used to print
       "October 18, 2026" five times in a row. The date is a quiet divider
       now - the post's title is what the member reads. */
    $lastDay  = null;
    $postCommentsById = $postComments ?? [];
    ?>
    <section class="wall-feed-section">
        <div class="wall-feed-head">
            <h2 class="wall-feed-title">Studio updates</h2>
            <span class="wall-feed-count"><?= count($items) ?> update<?= count($items) === 1 ? '' : 's' ?></span>
        </div>

        <div class="wall-feed">
            <?php foreach ($items as $item): ?>
                <?php
                $postDate = new \DateTimeImmutable((string) ($item['created_at'] ?? 'now') . ' UTC');
                $postDay  = $postDate->format('Y-m-d');
                $newDay   = $postDay !== $lastDay;
                $lastDay  = $postDay;
                $comments = $postCommentsById[(int) ($item['postId'] ?? 0)] ?? [];
                ?>
                <?php if ($newDay): ?>
                    <div class="wall-day"><span><?= e($postDate->format('F j, Y')) ?></span></div>
                <?php endif; ?>
                <?php require __DIR__ . '/partials/wall_post.php'; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <?php // The wall is paged, so the older posts are reachable. ?>
    <?php require __DIR__ . '/partials/pagination.php'; ?>
<?php endif; ?>

<?php if (\App\Core\Auth::user() === null): ?>
    <div class="favorites-section">
        <div class="empty-state">
            <p class="muted">Join the site to comment on wall posts.</p>
            <a class="btn btn-sm" href="<?= e(url('/signup')) ?>">Create an account</a>
        </div>
    </div>
<?php endif; ?>
