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
