<?php
/**
 * Shared comment thread for an entity.
 *
 * Expects:
 *   $comments        list<array>  Comment::forEntity(...) rows
 *   $commentCount    int
 *   $commentableType string       gallery | wall_post | photo
 *   $commentableId   int
 *   $commentReplies  array<int, list<array>>  the replies per comment
 *   $commentReactions array<int, array<string,int>>  reaction => count
 *   $myReactions     array<int, list<string>>  the reactions this member left
 */
$commentableType = $commentableType ?? 'gallery';
$commentableId   = (int) ($commentableId ?? 0);
$comments        = $comments ?? [];
$commentCount    = (int) ($commentCount ?? count($comments));
// Whether the caller passed them in (before the defaults): the defaults
// would make isset() true and the computation would never run.
$hasReplies   = isset($commentReplies);
$hasReactions = isset($commentReactions);
$hasMine      = isset($myReactions);
$commentReplies   = $commentReplies ?? [];
$commentReactions = $commentReactions ?? [];
$myReactions     = $myReactions ?? [];
$viewerId        = (int) (\App\Core\Auth::user()['id'] ?? 0);
$reactions       = ['like' => '👍', 'love' => '❤️'];

// The replies and the reactions are worked out here, so the pages that
// include this partial do not have to pass them in.
if (!$hasReplies || !$hasReactions || !$hasMine) {
    $commentIds = array_map(static fn (array $c): int => (int) $c['id'], $comments);
    $commentReplies   = \App\Models\Comment::repliesFor($commentIds);
    $commentReactions = \App\Models\Comment::reactionsFor($commentIds);
    $myReactions     = \App\Models\Comment::myReactions($viewerId, $commentIds);
}
?>
<section id="comments" style="margin-top:2rem;">
    <h2>Comments (<?= (int) $commentCount ?>)</h2>

    <?php if (empty($comments)): ?>
        <p class="muted">No comments yet.</p>
    <?php else: ?>
        <div style="display:grid;gap:1rem;margin-bottom:1rem;">
            <?php require __DIR__ . '/comment_thread.php'; ?>
        </div>
    <?php endif; ?>

    <?php if (\App\Core\Auth::check()): ?>
        <form method="post" action="<?= url('/comments') ?>" style="display:flex;gap:.5rem;align-items:flex-start;flex-wrap:wrap;">
            <?= csrf_field() ?>
            <input type="hidden" name="commentable_type" value="<?= e($commentableType) ?>">
            <input type="hidden" name="commentable_id" value="<?= (int) $commentableId ?>">
            <textarea name="body" rows="3" aria-label="Your comment" style="flex:1;min-width:220px;" placeholder="Leave a comment… (max 2000 chars)" maxlength="2000"></textarea>
            <button type="submit" class="btn">Post comment</button>
        </form>
    <?php else: ?>
        <p class="muted"><a href="<?= url('/signup') ?>">Create an account</a> or <a href="<?= url('/login') ?>">log in</a> to comment.</p>
    <?php endif; ?>
</section>
