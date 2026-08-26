<?php $title = 'Support #' . (int) $ticket['id']; ?>

<div class="support-thread">
<header class="support-thread-header">
    <div><a class="support-back" href="<?= url('/support') ?>">&larr; All tickets</a><h1>#<?= (int) $ticket['id'] ?> &middot; <?= e($ticket['subject']) ?></h1></div>
    <div class="support-thread-status"><span class="status-badge support-status-<?= e($ticket['status']) ?>"><?= e(ucfirst($ticket['status'])) ?></span></div>
</header>
<?php $isClosed = in_array($ticket['status'], ['resolved', 'ignored'], true); ?>
<div class="support-status-banner<?= $isClosed ? ' closed' : '' ?>"><strong><?= $isClosed ? 'Ticket closed' : 'Ticket status' ?></strong><span><?= $isClosed ? 'This conversation is no longer accepting replies.' : 'We will post updates and replies here.' ?></span></div>
<section class="support-conversation" aria-labelledby="conversation-heading">
    <h2 id="conversation-heading">Conversation</h2>
    <article class="support-message original"><div class="support-message-meta"><strong>Your original message</strong><small><?= e($ticket['created_at']) ?></small></div><div class="support-message-body"><?= nl2br(e($ticket['message'])) ?></div></article>
    <?php if (empty($replies)): ?><p class="muted">No replies yet. We will add updates to this conversation.</p><?php endif; ?>
    <?php foreach ($replies as $reply): ?>
        <article class="support-message <?= $reply['author_role'] === 'admin' ? 'admin' : 'user-reply' ?>">
            <div class="support-message-meta"><strong><?= $reply['author_role'] === 'admin' ? 'Support team' : 'Your reply' ?></strong><small><?= e($reply['created_at']) ?></small></div>
            <div class="support-message-body"><?= nl2br(e($reply['message'])) ?></div>
        </article>
    <?php endforeach; ?>
</section>
<?php if ($isClosed): ?>
    <p class="support-closed"><strong>Closed ticket.</strong> Contact support with a new ticket if you still need help.</p>
<?php else: ?>
    <form method="post" action="<?= url('/support/' . (int) $ticket['id'] . '/reply') ?>" class="card support-composer">
        <h2>Reply to support</h2><p class="muted">Add any details that will help us resolve your request.</p>
        <div class="support-form"><?= csrf_field() ?><p><label for="reply-message">Your reply</label><textarea id="reply-message" name="message" rows="6" maxlength="10000" required></textarea></p><p class="support-form-actions"><button type="submit" class="btn">Send Reply</button></p></div>
    </form>
<?php endif; ?>
</div>
