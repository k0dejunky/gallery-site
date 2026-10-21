<?php
/**
 * A comment thread: the list of comments with their reactions and their
 * replies.
 *
 * The wall's comment thread used to be a VERBATIM COPY of the markup in
 * comments.php, so a change to a comment had to be made twice and the
 * two drifted. This is the one copy.
 *
 * Expects:
 *   $comments         list<array>  the comments
 *   $commentReplies   array<int, list<array>>  the replies per comment
 *   $commentReactions array<int, array<string,int>>  reaction => count
 *   $myReactions      array<int, list<string>>  the reactions this member left
 * The last three are worked out here when they are not passed in.
 */
$comments        = $comments ?? [];
$hasReplies   = isset($commentReplies);
$hasReactions = isset($commentReactions);
$hasMine      = isset($myReactions);
$commentReplies   = $commentReplies ?? [];
$commentReactions = $commentReactions ?? [];
$myReactions     = $myReactions ?? [];
$viewerId        = (int) (\App\Core\Auth::user()['id'] ?? 0);
$reactions       = ['like' => '\u{1F44D}', 'love' => '\u{2764}\u{FE0F}'];

if (!$hasReplies || !$hasReactions || !$hasMine) {
    $commentIds = array_map(static fn (array $c): int => (int) $c['id'], $comments);
    $commentReplies   = \App\Models\Comment::repliesFor($commentIds);
    $commentReactions = \App\Models\Comment::reactionsFor($commentIds);
    $myReactions     = \App\Models\Comment::myReactions($viewerId, $commentIds);
}
?>
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
