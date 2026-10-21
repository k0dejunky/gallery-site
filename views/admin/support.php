<?php $title = 'Support Messages'; ?>

<?php
$statusCounts = array_fill_keys(['new', 'read', 'postponed', 'resolved', 'ignored'], 0);
foreach ($messages as $supportMessage) {
    if (isset($statusCounts[$supportMessage['status']])) {
        $statusCounts[$supportMessage['status']]++;
    }
}
$totalOpen = $statusCounts['new'] + $statusCounts['read'] + $statusCounts['postponed'];
?>

<?php /* The ticket cards still use the member stylesheet (.admin-ticket*). The
       surface tokens they reference are remapped in admin.css, so they now read
       as neutral admin cards; only the summary strip and the empty state needed
       replacing with the shared stat/empty components. */ ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Tickets</h2>
        <p><?= $totalOpen ? number_format($totalOpen) . ' open' : 'Nothing open' ?><?= count($messages) ? ' of ' . number_format(count($messages)) . ' shown' : '' ?></p>
    </div>

    <div class="stat-cards">
        <div class="stat-card kpi">
            <small>New</small>
            <b><?= number_format($statusCounts['new']) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Read</small>
            <b><?= number_format($statusCounts['read']) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Postponed</small>
            <b><?= number_format($statusCounts['postponed']) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Resolved</small>
            <b><?= number_format($statusCounts['resolved']) ?></b>
        </div>
        <div class="stat-card kpi">
            <small>Ignored</small>
            <b><?= number_format($statusCounts['ignored']) ?></b>
        </div>
    </div>

    <?php if (empty($messages)): ?>
        <?= admin_empty('lifebuoy', 'No support messages yet', 'Messages sent from the contact form land here.') ?>
    <?php else: ?>
        <div class="admin-ticket-list">
            <?php foreach ($messages as $supportMessage): ?>
                <a class="admin-ticket" href="<?= url('/admin/support/' . (int) $supportMessage['id']) ?>">
                    <div><h2>#<?= (int) $supportMessage['id'] ?> &middot; <?= e($supportMessage['subject']) ?></h2><div class="admin-ticket-details"><span><?= e($supportMessage['email']) ?></span><span><?= (int) $supportMessage['reply_count'] ?> repl<?= (int) $supportMessage['reply_count'] === 1 ? 'y' : 'ies' ?></span><span>Opened <?= e(tzdate('M j, Y g:i', $supportMessage['created_at'])) ?></span></div></div>
                    <div class="admin-ticket-side"><span class="status-badge support-status-<?= e($supportMessage['status']) ?>"><?= e(ucfirst($supportMessage['status'])) ?></span><small><?= e(tzdate('M j, Y g:i', $supportMessage['updated_at'] ?? $supportMessage['created_at'])) ?></small><span class="btn btn-sm btn-outline admin-ticket-open">Open ticket &rarr;</span></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php /* The live chat had NO moderation screen at all: a member's message
       stayed in the replay for everyone to see, and there was nowhere to
       look at what was said or to take a message down. The member chat has
       a moderation path; the live chat had none. */ ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2>Live chat</h2>
        <p>Messages posted in a live stream. <?= number_format((int) ($liveCount ?? 0)) ?> in the last hour.</p>
    </div>

    <?php if (empty($liveMessages)): ?>
        <?= admin_empty('broadcast', 'No live chat messages', 'Messages posted during a live stream land here, so they can be read and removed.') ?>
    <?php else: ?>
        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Member</th>
                        <th>Message</th>
                        <th>Stream</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($liveMessages as $live): ?>
                    <tr>
                        <td class="user-date"><?= e(tzdate('M j, g:ia', (string) $live['created_at'])) ?></td>
                        <td><?= e((string) $live['email']) ?></td>
                        <td>
                            <?php if (($live['sender_role'] ?? 'user') === 'user'): ?>
                                <?= e((string) $live['message']) ?>
                            <?php else: ?>
                                <span class="muted"><?= e((string) $live['message']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="user-date">#<?= (int) $live['session_id'] ?> (<?= e((string) ($live['session_status'] ?? '')) ?>)</td>
                        <td class="act">
                            <form method="post" action="<?= url('/admin/support/live-chat/' . (int) $live['id'] . '/delete') ?>" class="inline"
                                  data-confirm="Remove this live chat message? It disappears from the chat and the replay.">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                            </form>
                            <form method="post" action="<?= url('/admin/support/live-chat/' . (int) $live['session_id'] . '/user/' . (int) $live['user_id'] . '/delete') ?>" class="inline"
                                  data-confirm="Remove every message from this member in this stream?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline">Remove all from this member</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
