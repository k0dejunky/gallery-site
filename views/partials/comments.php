<?php
/**
 * Shared comment thread for an entity.
 *
 * Expects:
 *   $comments        list<array>  Comment::forEntity(...) rows
 *   $commentCount    int
 *   $commentableType string       gallery | wall_post | photo
 *   $commentableId   int
 */
$commentableType = $commentableType ?? 'gallery';
$commentableId   = (int) ($commentableId ?? 0);
$comments        = $comments ?? [];
$commentCount    = (int) ($commentCount ?? count($comments));
?>
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
                        <?php if (($comment['source'] ?? '') === 'wall_post'): ?>
                            <span class="muted"> &middot; via the Wall</span>
                        <?php endif; ?>
                    </p>
                    <p style="margin:0;"><?= e($comment['body']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (\App\Core\Auth::check()): ?>
        <form method="post" action="<?= url('/comments') ?>" style="display:flex;gap:.5rem;align-items:flex-start;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="commentable_type" value="<?= e($commentableType) ?>">
            <input type="hidden" name="commentable_id" value="<?= (int) $commentableId ?>">
            <textarea name="body" rows="3" style="flex:1;min-width:220px;" placeholder="Leave a comment… (max 2000 chars)" maxlength="2000"></textarea>
            <button type="submit" class="btn">Post comment</button>
        </form>
    <?php else: ?>
        <p class="muted"><a href="<?= url('/signup') ?>">Create an account</a> or <a href="<?= url('/login') ?>">log in</a> to comment.</p>
    <?php endif; ?>
</section>