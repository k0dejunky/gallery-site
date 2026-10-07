<?php $title = 'Notifications'; ?>

<div class="favorites-hero">
    <p class="eyebrow">Inbox</p>
    <h1>Notifications</h1>
    <p class="muted">New posts, replies and activity on your account.</p>
</div>

<?php if (!empty($unreadIds)): ?>
    <form method="post" action="<?= url('/notifications/read-all') ?>" style="margin-bottom:1rem;">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline">Mark all as read</button>
    </form>
<?php endif; ?>

<?php if (empty($notifications)): ?>
    <div class="favorites-section">
        <div class="empty-state">
            <p>Nothing here yet.</p>
            <a class="btn btn-sm" href="<?= e(url('/galleries')) ?>">Browse galleries</a>
        </div>
    </div>
<?php else: ?>
    <section class="favorites-section">
        <?php foreach ($notifications as $notification): ?>
            <div class="comment-block" style="display:flex;gap:.75rem;align-items:flex-start;padding:.6rem 0;border-bottom:1px solid var(--card-border,#efe7ec);<?= $notification['read_at'] === null ? 'font-weight:600;' : '' ?>">
                <?php if ($notification['read_at'] === null): ?>
                    <span title="Unread" aria-label="Unread" style="margin-top:.3rem;">&#9679;</span>
                <?php endif; ?>
                <div style="flex:1;min-width:0;">
                    <p style="margin:0;">
                        <?php if ($notification['url'] !== ''): ?>
                            <a href="<?= url('/notifications/' . (int) $notification['id']) ?>"><?= e($notification['title']) ?></a>
                        <?php else: ?>
                            <?= e($notification['title']) ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($notification['body'] !== null && $notification['body'] !== ''): ?>
                        <p class="muted" style="margin:.15rem 0 0;"><?= e($notification['body']) ?></p>
                    <?php endif; ?>
                    <p class="muted" style="margin:.1rem 0 0;font-size:.8rem;"><?= e(tzdate('M j, g:ia', (string) $notification['created_at'])) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>