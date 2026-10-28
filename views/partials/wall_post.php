<?php
/**
 * ONE wall card.
 *
 * The page used to carry this markup TWICE - once in the notifications
 * loop and once in the studio-posts loop - and the two drifted apart (one
 * had the "Members only" chip, the other did not; one had the photo
 * count, the other had it in a different place). The merged timeline gives
 * every item the same shape (see WallController::buildFeed), so there is
 * one card.
 *
 * Expects:
 *   $item     the feed item: kind, badge, tone, title, body, url, preview,
 *             locked, count, comments, postId, isNew, age, pinned
 *   $comments the comment rows of a studio post (optional)
 */
$item     = is_array($item ?? null) ? $item : [];
$badge    = (string) ($item['badge'] ?? 'Update');
$tone     = (string) ($item['tone'] ?? 'default');
$title    = (string) ($item['title'] ?? '');
$body     = (string) ($item['body'] ?? '');
$url      = (string) ($item['url'] ?? '');
$preview  = is_array($item['preview'] ?? null) ? $item['preview'] : null;
$locked   = !empty($item['locked']);
$count    = (int) ($item['count'] ?? 0);
$commentCount = (int) ($item['comments'] ?? 0);
$postId   = $item['postId'] ?? null;
$isNew    = !empty($item['isNew']);
$age      = (int) ($item['age'] ?? 0);
$creator  = (string) (\App\Models\SiteProfile::get('display_name', 'Amethyst'));
$initial  = mb_strtoupper(mb_substr($creator, 0, 1));
$photos   = is_array($preview['photos'] ?? null) ? $preview['photos'] : [];
$visible  = count($photos);
$extra    = max(0, $count - $visible);
?>
<article class="wall-post<?= $isNew ? ' wall-post-unread' : '' ?>">
    <header class="wall-post-head">
        <span class="wall-post-avatar wall-post-avatar--<?= e($tone) ?>" aria-hidden="true"><?= e($initial) ?></span>
        <div class="wall-post-meta">
            <strong class="wall-post-author"><?= e($creator) ?></strong>
            <span class="wall-post-sub">
                <span class="wall-post-badge wall-post-badge--<?= e($tone) ?>"><?= e($badge) ?></span>
                <time class="wall-post-time"
                      datetime="<?= e((string) (new \DateTimeImmutable((string) ($item['created_at'] ?? 'now') . ' UTC'))->format(DATE_ATOM)) ?>"
                      title="<?= e(tzdate('M j, Y g:ia', (string) ($item['created_at'] ?? ''))) ?>"><?= e(time_ago($age)) ?></time>
            </span>
        </div>
        <?php if ($isNew): ?><span class="wall-post-new">New</span><?php endif; ?>
        <?php if (!empty($item['pinned'])): ?><span class="wall-post-badge wall-post-badge--pinned">Pinned</span><?php endif; ?>
        <?php if ($preview !== null): ?>
            <a class="wall-post-open" href="<?= e($url) ?>">View set<span aria-hidden="true"> &rarr;</span></a>
        <?php endif; ?>
    </header>

    <?php if ($title !== ''): ?>
        <a class="wall-post-title" href="<?= e($url) ?>"><?= e($title) ?></a>
    <?php endif; ?>
    <?php if ($body !== '' && $preview === null): ?>
        <p class="wall-post-body"><?= e($body) ?></p>
    <?php endif; ?>

    <?php if ($photos !== []): ?>
        <a class="wall-photos wall-photos-<?= min($visible, 3) ?>" href="<?= e($url) ?>">
            <?php foreach ($photos as $idx => $photo): ?>
                <?php
                $size = $locked ? 'blur' : 'thumb';
                $src  = file_url($photo['filename'], $size);
                ?>
                <span class="wall-photo">
                    <img src="<?= e($src) ?>" alt="<?= e($photo['caption'] !== '' ? $photo['caption'] : ($title !== '' ? $title : $badge)) ?>" loading="lazy" decoding="async">
                    <?php if (!empty($photo['is_video'])): ?><span class="video-badge" aria-hidden="true">&#9654;</span><?php endif; ?>
                    <?php if ($locked && $idx === 0): ?>
                        <span class="wall-photo-lock"><span class="chip">Members only</span></span>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
            <?php if ($extra > 0): ?>
                <span class="wall-photo-more" aria-hidden="true">+<?= number_format($extra) ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>

    <footer class="wall-post-foot">
        <span class="wall-post-facts">
            <?php if ($count > 0): ?>
                <span class="wall-post-count"><?= number_format($count) ?> photo<?= $count === 1 ? '' : 's' ?></span>
            <?php endif; ?>
            <?php if ($postId !== null): ?>
                <details class="wall-comments">
                    <summary class="wall-comments-toggle">
                        <?= \App\Core\Icon::svg('chat', 'wall-comments-ico') ?>
                        <?= $commentCount === 1 ? '1 comment' : $commentCount . ' comments' ?>
                    </summary>
                    <div class="wall-comments-body">
                        <?php
                        /* The thread is the shared one: this used to be a
                           verbatim copy of the markup in comments.php, so a
                           change to a comment had to be made twice. */
                        $comments = $comments ?? [];
                        require __DIR__ . '/comment_thread.php';
                        ?>
                        <?php if (\App\Core\Auth::user() !== null): ?>
                            <form method="post" action="<?= url('/comments') ?>" class="wall-composer">
                                <?= csrf_field() ?>
                                <input type="hidden" name="commentable_type" value="wall_post">
                                <input type="hidden" name="commentable_id" value="<?= (int) $postId ?>">
                                <textarea name="body" rows="2" aria-label="Your comment" placeholder="Leave a comment" maxlength="2000"></textarea>
                                <button type="submit" class="btn btn-sm">Post comment</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </span>
        <?php if ($locked): ?>
            <span class="wall-post-lock">
                <span class="chip">Members only</span>
                <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">Upgrade to view</a>
            </span>
        <?php endif; ?>
    </footer>
</article>
