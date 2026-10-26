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
