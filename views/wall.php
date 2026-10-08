<?php $title = 'Wall'; ?>

<div class="favorites-hero">
    <p class="eyebrow">From the creator</p>
    <h1>The Wall</h1>
    <p class="muted">New sets and studio updates, right as they land.</p>
</div>

<?php if (!empty($notifications)): ?>
    <section class="favorites-section">
        <div class="favorites-heading">
            <h2>New for you</h2>
            <?php if (!empty($unreadIds)): ?>
                <form method="post" action="<?= url('/notifications/read-all') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="wall-feed">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $isUnread  = $notification['read_at'] === null;
                $notifType = (string) $notification['type'];
                $notifTag  = match ($notifType) {
                    'gallery'  => 'New set',
                    'wall'     => 'Wall post',
                    'reply'    => 'Reply',
                    'purchase' => 'Membership',
                    default    => 'Update',
                };
                $notifColor = match ($notifType) {
                    'gallery'  => '#b42318',
                    'purchase' => '#0f766e',
                    'reply'    => '#7c3aed',
                    default    => '#99618a',
                };
                $hasPreview = !empty($notification['preview']);
                $openUrl    = url('/notifications/' . (int) $notification['id']);
                ?>
                <article class="wall-post<?= $isUnread ? ' wall-post-unread' : '' ?>">
                    <header class="wall-post-head">
                        <span class="wall-post-avatar" style="background:<?= $notifColor ?>"><?= e(str_split($notifTag)[0]) ?></span>
                        <div class="wall-post-meta">
                            <strong><?= e($notifTag) ?></strong>
                            <span class="wall-post-time"><?= e(tzdate('M j, g:ia', (string) $notification['created_at'])) ?></span>
                        </div>
                        <?php if ($isUnread): ?><span class="chip wall-post-new">New</span><?php endif; ?>
                        <a class="btn btn-sm btn-outline wall-post-open" href="<?= e($openUrl) ?>">View set</a>
                    </header>

                    <?php if ($hasPreview): ?>
                        <?php
                        $preview = $notification['preview'];
                        $photoCount = (int) $preview['count'];
                        $showLock   = empty($preview['can_view']);
                        ?>
                        <a class="wall-post-title" href="<?= e($openUrl) ?>">
                            <?= e($preview['title']) ?>
                        </a>
                        <?php if ($preview['description'] !== ''): ?>
                            <a class="wall-post-desc" href="<?= e($openUrl) ?>"><?= e($preview['description']) ?></a>
                        <?php endif; ?>

                        <?php if (!empty($preview['photos'])): ?>
                            <?php $photoCountVisible = count($preview['photos']); ?>
                            <a class="wall-photos wall-photos-<?= min($photoCountVisible, 3) ?>" href="<?= e($openUrl) ?>">
                                <?php foreach ($preview['photos'] as $idx => $photo): ?>
                                    <?php
                                    $size = $showLock ? 'blur' : 'thumb';
                                    $src  = file_url($photo['filename'], $size);
                                    ?>
                                    <span class="wall-photo">
                                        <img src="<?= e($src) ?>" alt="<?= e($photo['caption'] !== '' ? $photo['caption'] : $preview['title']) ?>" loading="lazy" decoding="async">
                                        <?php if ($photo['is_video']): ?><span class="video-badge">&#9654;</span><?php endif; ?>
                                        <?php if ($showLock && $idx === 0): ?>
                                            <span class="wall-photo-lock"><span class="chip">Members only</span></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </a>
                        <?php endif; ?>

                        <footer class="wall-post-foot">
                            <span class="muted">
                                <?php if ($photoCount > 0): ?><?= number_format($photoCount) ?> photo<?= $photoCount === 1 ? '' : 's' ?><?php endif; ?>
                            </span>
                            <?php if ($showLock): ?>
                                <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">Upgrade to view</a>
                            <?php endif; ?>
                        </footer>
                    <?php else: ?>
                        <a class="wall-post-title" href="<?= e($openUrl) ?>"><?= e($notification['title'] ?? '') ?></a>
                        <?php if (!empty($notification['body'])): ?>
                            <a class="wall-post-desc" href="<?= e($openUrl) ?>"><?= e($notification['body']) ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if (empty($posts) && empty($notifications)): ?>
    <div class="favorites-section">
        <div class="empty-state">
            <p>Nothing new yet — the wall fills up as soon as the studio posts.</p>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse galleries</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <?php $postPreview = $post['preview'] ?? null; ?>
        <section class="favorites-section">
            <div class="favorites-heading">
                <h2><?php $postDate = new DateTimeImmutable($post['created_at']); echo e($postDate->format('F j, Y')); ?></h2>
                <?php if (!empty($post['pinned'])): ?><span class="chip">Pinned</span><?php endif; ?>
            </div>

            <?php if ($postPreview !== null): ?>
                <?php
                $postShowLock = empty($postPreview['can_view']);
                $postOpenUrl  = url('/galleries/' . (int) $postPreview['gallery_id']);
                ?>
                <article class="wall-post">
                    <header class="wall-post-head">
                        <span class="wall-post-avatar" style="background:#99618a">N</span>
                        <div class="wall-post-meta">
                            <strong>New set</strong>
                            <span class="wall-post-time"><?= e(tzdate('M j, g:ia', (string) $post['created_at'])) ?></span>
                        </div>
                        <a class="btn btn-sm btn-outline wall-post-open" href="<?= e($postOpenUrl) ?>">View set</a>
                    </header>

                    <a class="wall-post-title" href="<?= e($postOpenUrl) ?>"><?= e($postPreview['title']) ?></a>
                    <?php if ($postPreview['description'] !== ''): ?>
                        <a class="wall-post-desc" href="<?= e($postOpenUrl) ?>"><?= e($postPreview['description']) ?></a>
                    <?php endif; ?>

                    <?php if (!empty($postPreview['photos'])): ?>
                        <?php $postPhotoCountVisible = count($postPreview['photos']); ?>
                        <a class="wall-photos wall-photos-<?= min($postPhotoCountVisible, 3) ?>" href="<?= e($postOpenUrl) ?>">
                            <?php foreach ($postPreview['photos'] as $idx => $photo): ?>
                                <?php
                                $size = $postShowLock ? 'blur' : 'thumb';
                                $src  = file_url($photo['filename'], $size);
                                ?>
                                <span class="wall-photo">
                                    <img src="<?= e($src) ?>" alt="<?= e($photo['caption'] !== '' ? $photo['caption'] : $postPreview['title']) ?>" loading="lazy" decoding="async">
                                    <?php if ($photo['is_video']): ?><span class="video-badge">&#9654;</span><?php endif; ?>
                                    <?php if ($postShowLock && $idx === 0): ?>
                                        <span class="wall-photo-lock"><span class="chip">Members only</span></span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </a>
                    <?php endif; ?>

                    <footer class="wall-post-foot">
                        <?php if ((int) $postPreview['count'] > 0): ?>
                            <span class="muted"><?= number_format((int) $postPreview['count']) ?> photo<?= (int) $postPreview['count'] === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                        <?php if ($postShowLock): ?>
                            <a class="btn btn-sm" href="<?= e(url('/membership')) ?>">Upgrade to view</a>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php else: ?>
                <p style="white-space:pre-line;margin-bottom:.5rem;"><?= e($post['body']) ?></p>
            <?php endif; ?>

            <?php $postComments = \App\Models\Comment::forEntity(\App\Models\Comment::TYPE_WALL, (int) $post['id']); ?>
            <details style="margin-top:.75rem;">
                <summary style="cursor:pointer;font-size:.9rem;" class="muted"><?= count($postComments) ?> comment<?= count($postComments) === 1 ? '' : 's' ?></summary>
                <div style="margin-top:.75rem;display:grid;gap:.75rem;">
                    <?php foreach ($postComments as $comment): ?>
                        <div id="comment-<?= (int) $comment['id'] ?>" style="border-left:3px solid var(--card-border,#ddd);padding-left:.75rem;">
                            <p style="margin:0 0 .15rem;">
                                <strong><?= \App\Models\Comment::isStaff($comment['role']) ? 'Site team' : 'Member' ?></strong>
                                <span class="muted"> · <?= e(tzdate('M j, g:ia', (string) $comment['created_at'])) ?></span>
                            </p>
                            <p style="margin:0;"><?= e($comment['body']) ?></p>
                        </div>
                    <?php endforeach; ?>
                    <?php if (\App\Core\Auth::user() !== null): ?>
                        <form method="post" action="<?= url('/comments') ?>" style="display:flex;gap:.5rem;align-items:flex-start;flex-wrap:wrap;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="commentable_type" value="wall_post">
                            <input type="hidden" name="commentable_id" value="<?= (int) $post['id'] ?>">
                            <textarea name="body" rows="2" style="flex:1;min-width:180px;" placeholder="Say something… (max 2000 chars)" maxlength="2000"></textarea>
                            <button type="submit" class="btn btn-sm">Post comment</button>
                        </form>
                    <?php endif; ?>
                </div>
            </details>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (\App\Core\Auth::user() === null): ?>
    <div class="favorites-section">
        <div class="empty-state">
            <p class="muted">Join the site to comment on wall posts.</p>
            <a class="btn btn-sm" href="<?= e(url('/signup')) ?>">Create an account</a>
        </div>
    </div>
<?php endif; ?>