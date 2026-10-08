<?php $title = 'Wall'; ?>

<div class="favorites-hero">
    <p class="eyebrow">From the creator</p>
    <h1>The Wall</h1>
    <p class="muted">Your notifications, then news and updates from the studio.</p>
</div>

<?php if (!empty($notifications)): ?>
    <section class="favorites-section">
        <div class="favorites-heading">
            <h2>Your notifications</h2>
            <?php if (!empty($unreadIds)): ?>
                <form method="post" action="<?= url('/notifications/read-all') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-sm btn-outline">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>

        <?php foreach ($notifications as $notification): ?>
            <?php
            $isUnread   = $notification['read_at'] === null;
            $notifType  = (string) $notification['type'];
            $notifTag   = match ($notifType) {
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
            ?>
            <article class="comment-block" style="display:flex;gap:.75rem;align-items:flex-start;padding:.75rem 0;border-bottom:1px solid var(--card-border,#efe7ec);">
                <span title="<?= e($notifTag) ?>" aria-hidden="true" style="flex:0 0 auto;margin-top:.1rem;width:2rem;height:2rem;border-radius:999px;display:flex;align-items:center;justify-content:center;background:<?= $notifColor ?>;color:#fff;font-size:.7rem;font-weight:700;letter-spacing:.03em;"><?= e(str_split($notifTag)[0]) ?></span>
                <div style="flex:1;min-width:0;">
                    <p style="margin:0;">
                        <?php if ($notification['url'] !== ''): ?>
                            <a href="<?= url('/notifications/' . (int) $notification['id']) ?>" style="text-decoration:underline;text-underline-offset:2px;"><?= e($notification['title']) ?></a>
                        <?php else: ?>
                            <?= e($notification['title']) ?>
                        <?php endif; ?>
                        <?php if ($isUnread): ?><span class="chip" style="background:#b42318;color:#fff;font-size:.7rem;">New</span><?php endif; ?>
                    </p>
                    <?php if ($notification['body'] !== null && $notification['body'] !== ''): ?>
                        <p class="muted" style="margin:.15rem 0 0;"><?= e($notification['body']) ?></p>
                    <?php endif; ?>
                    <p class="muted" style="margin:.15rem 0 0;font-size:.78rem;"><?= e($notifTag) ?> · <?= e(tzdate('M j, g:ia', (string) $notification['created_at'])) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if (empty($posts) && empty($notifications)): ?>
    <div class="favorites-section">
        <div class="empty-state">
            <p>No wall posts yet — check back soon.</p>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse galleries</a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($posts as $post): ?>
        <section class="favorites-section">
            <div class="favorites-heading">
                <h2><?php $postDate = new DateTimeImmutable($post['created_at']); echo e($postDate->format('F j, Y')); ?></h2>
                <?php if (!empty($post['pinned'])): ?><span class="chip">Pinned</span><?php endif; ?>
            </div>
            <p style="white-space:pre-line;margin-bottom:.5rem;"><?= e($post['body']) ?></p>

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