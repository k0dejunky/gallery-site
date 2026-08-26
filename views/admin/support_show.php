<?php $title = 'Support #' . (int) $ticket['id']; ?>

<?php $isClosed = in_array($ticket['status'], ['resolved', 'ignored'], true); ?>
<div class="admin-support-thread">
<header class="admin-thread-header">
    <div><a class="support-back" href="<?= url('/admin/support') ?>">&larr; Support tickets</a><h1>#<?= (int) $ticket['id'] ?> &middot; <?= e($ticket['subject']) ?></h1><p class="admin-ticket-meta"><span><?= e($ticket['email']) ?></span><span>Opened <?= e($ticket['created_at']) ?></span><span><?= count($replies) ?> repl<?= count($replies) === 1 ? 'y' : 'ies' ?></span></p></div>
    <span class="status-badge support-status-<?= e($ticket['status']) ?>"><?= e(ucfirst($ticket['status'])) ?></span>
</header>
<div class="support-status-banner<?= $isClosed ? ' closed' : '' ?>"><strong><?= $isClosed ? 'Closed ticket' : 'Conversation open' ?></strong><span><?= $isClosed ? 'Replies are disabled until the ticket is reopened.' : 'Reply to the customer or update the status below.' ?></span></div>
<section class="support-conversation" aria-labelledby="admin-thread-heading">
    <h2 id="admin-thread-heading">Conversation timeline</h2>
    <article class="support-message original"><div class="support-message-meta"><strong>Original message</strong><small><?= e($ticket['created_at']) ?></small></div><div class="support-message-body"><?= nl2br(e($ticket['message'])) ?></div></article>
    <?php foreach ($replies as $reply): ?>
        <article class="support-message <?= $reply['author_role'] === 'admin' ? 'admin' : 'user-reply' ?>">
            <div class="support-message-meta"><strong><?= $reply['author_role'] === 'admin' ? 'Admin reply' : 'Customer reply' ?></strong><small><?= e($reply['created_at']) ?></small></div>
            <div class="support-message-body"><?= nl2br(e($reply['message'])) ?></div>
        </article>
    <?php endforeach; ?>
</section>
<?php if (!$isClosed): ?>
    <form method="post" action="<?= url('/admin/support/' . (int) $ticket['id'] . '/reply') ?>" class="card support-composer">
        <h2>Reply to customer</h2><p class="muted">The customer will receive this response by email.</p>
        <div class="support-form"><?= csrf_field() ?><p><label for="admin-reply">Your reply</label><textarea id="admin-reply" name="message" rows="6" maxlength="10000" required></textarea></p><p class="support-form-actions"><button type="submit" class="btn">Send Reply</button></p></div>
    </form>
<?php endif; ?>
<div class="admin-thread-actions">
    <strong>Set status</strong>
    <?php foreach (['read', 'postponed', 'resolved', 'ignored'] as $status): ?>
        <form method="post" action="<?= url('/admin/support/' . (int) $ticket['id'] . '/status') ?>" class="inline">
            <?= csrf_field() ?><input type="hidden" name="status" value="<?= e($status) ?>"><button class="btn btn-sm btn-outline" type="submit"><?= e(ucfirst($status)) ?></button>
        </form>
    <?php endforeach; ?>
    <form method="post" action="<?= url('/admin/support/' . (int) $ticket['id'] . '/delete') ?>" class="inline" onsubmit="return confirm('Delete this ticket and its replies permanently?');">
        <?= csrf_field() ?><button class="btn btn-sm btn-danger" type="submit">Delete</button>
    </form>
</div>
</div>
