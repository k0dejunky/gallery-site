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
            <?php foreach ($comments as $comment): ?>
                <?php
                $cid      = (int) $comment['id'];
                $replies  = $commentReplies[$cid] ?? [];
                $counts   = $commentReactions[$cid] ?? [];
                $mine     = $myReactions[$cid] ?? [];
                ?>
                <div id="comment-<?= $cid ?>" style="border-left:3px solid var(--card-border);padding-left:.75rem;">
                    <p style="margin:0 0 .15rem;">
                        <strong><?= \App\Models\Comment::isStaff((string) ($comment['role'] ?? '')) ? 'Site team' : 'Member' ?></strong>
                        <span class="muted"> &middot; <?= e(tzdate('M j, g:ia', (string) $comment['created_at'])) ?></span>
                        <?php if (($comment['source'] ?? '') === 'wall_post'): ?>
                            <span class="muted"> &middot; via the Wall</span>
                        <?php endif; ?>
                    </p>
                    <p class="u-m0"><?= e($comment['body']) ?></p>

                    <?php /* The reactions: one per member, toggled. */ ?>
                    <div class="comment-reactions">
                        <?php foreach ($reactions as $key => $emoji): ?>
                            <?php $on = in_array($key, $mine, true); $n = (int) ($counts[$key] ?? 0); ?>
                            <form method="post" action="<?= url('/comments/react') ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="comment_id" value="<?= $cid ?>">
                                <input type="hidden" name="reaction" value="<?= e($key) ?>">
                                <button type="submit" class="comment-reaction<?= $on ? ' is-on' : '' ?>"
                                        aria-pressed="<?= $on ? 'true' : 'false' ?>"
                                        title="<?= $on ? 'Remove your reaction' : 'React' ?>">
                                    <span aria-hidden="true"><?= $emoji ?></span>
                                    <span class="comment-reaction-count"><?= $n > 0 ? (int) $n : '' ?></span>
                                    <span class="sr-only"><?= $on ? 'Remove your reaction' : 'React' ?></span>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>

                    <?php /* The replies. */ ?>
                    <?php if (!empty($replies)): ?>
                        <div class="comment-replies">
                            <?php foreach ($replies as $reply): ?>
                                <div id="comment-<?= (int) $reply['id'] ?>" class="comment-reply">
                                    <p style="margin:0 0 .1rem;">
                                        <strong><?= \App\Models\Comment::isStaff((string) ($reply['role'] ?? '')) ? 'Site team' : 'Member' ?></strong>
                                        <span class="muted"> &middot; <?= e(tzdate('M j, g:ia', (string) $reply['created_at'])) ?></span>
                                    </p>
                                    <p class="u-m0"><?= e($reply['body']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($viewerId > 0): ?>
                        <details class="comment-reply-box">
                            <summary>Reply</summary>
                            <form method="post" action="<?= url('/comments/reply') ?>" style="display:flex;gap:.5rem;align-items:flex-start;flex-wrap:wrap;margin-top:.4rem;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="parent_id" value="<?= $cid ?>">
                                <textarea name="body" rows="2" aria-label="Your reply" style="flex:1;min-width:200px;" placeholder="Write a reply… (max 2000 chars)" maxlength="2000"></textarea>
                                <button type="submit" class="btn btn-sm">Post reply</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
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
