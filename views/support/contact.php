<?php $title = 'Support'; ?>

<div class="support-page">
<header class="support-hero">
    <div><h1>Support</h1><p>Questions, feedback, or something not working? We are here to help.</p></div>
</header>

<section aria-labelledby="your-tickets-heading">
    <div class="support-section-heading"><h2 id="your-tickets-heading">Your tickets</h2><span class="muted"><?= count($tickets) ?> total</span></div>
    <div class="support-ticket-list">
    <?php if (empty($tickets)): ?>
        <p class="card empty-state">You have no support tickets yet. Start a conversation below.</p>
    <?php else: ?>
        <?php foreach ($tickets as $ticket): ?>
             <a class="card support-ticket<?= empty($ticket['user_read_at']) && !empty($ticket['latest_admin_reply']) && strtotime($ticket['latest_admin_reply']) > strtotime($ticket['latest_user_reply'] ?: $ticket['created_at']) ? ' support-ticket-unread' : '' ?>" href="<?= url('/support/' . (int) $ticket['id']) ?>">
                <span class="support-ticket-main"><strong class="support-ticket-subject">#<?= (int) $ticket['id'] ?> &middot; <?= e($ticket['subject']) ?></strong><span class="support-ticket-meta"><span><?= (int) $ticket['reply_count'] ?> repl<?= (int) $ticket['reply_count'] === 1 ? 'y' : 'ies' ?></span><span aria-hidden="true">&middot;</span><span>Updated <?= e($ticket['updated_at'] ?? $ticket['created_at']) ?></span></span></span>
                 <span class="support-ticket-side"><span class="status-badge support-status-<?= e($ticket['status']) ?>"><?= e(ucfirst($ticket['status'])) ?></span><?php if (empty($ticket['user_read_at']) && !empty($ticket['latest_admin_reply']) && strtotime($ticket['latest_admin_reply']) > strtotime($ticket['latest_user_reply'] ?: $ticket['created_at'])): ?><small class="unread-label">Unread reply</small><?php endif; ?><small>View ticket &rarr;</small></span>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</section>

<section id="new-ticket" class="card support-new" aria-labelledby="new-ticket-heading">
    <h2 id="new-ticket-heading">Start a new conversation</h2>
    <p class="muted">Please do not include passwords or payment information.</p>
<form method="post" action="<?= url('/support') ?>" class="support-form">
    <?= csrf_field() ?>
    <p><label for="support-subject">Subject</label><input type="text" id="support-subject" name="subject" maxlength="255" required></p>
    <p><label for="support-message">Message</label><textarea id="support-message" name="message" rows="9" maxlength="10000" required></textarea></p>
    <p class="support-form-actions">
        <button type="submit" class="btn">Send Support Request</button>
        <a class="btn btn-outline" href="<?= url('/account') ?>">Back to Dashboard</a>
    </p>
</form>
</section>
</div>
