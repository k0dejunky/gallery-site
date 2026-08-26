<?php $title = 'Support Messages'; ?>

<?php
$statusCounts = array_fill_keys(['new', 'read', 'postponed', 'resolved', 'ignored'], 0);
foreach ($messages as $supportMessage) {
    if (isset($statusCounts[$supportMessage['status']])) {
        $statusCounts[$supportMessage['status']]++;
    }
}
?>
<div class="admin-support-page">
<header class="admin-support-header">
    <div><h1>Support Messages</h1><p class="muted">Review member questions and keep every conversation moving.</p></div>
    <a class="btn btn-outline" href="<?= url('/admin') ?>">Admin dashboard</a>
</header>

<div class="admin-support-summary" aria-label="Ticket summary">
    <?php foreach (['new' => 'New', 'read' => 'Read', 'postponed' => 'Postponed', 'resolved' => 'Resolved'] as $status => $label): ?>
        <span class="admin-support-count"><strong><?= $statusCounts[$status] ?></strong> <?= $label ?></span>
    <?php endforeach; ?>
</div>

<?php if (empty($messages)): ?>
    <p class="card admin-empty">No support messages have been submitted.</p>
<?php else: ?>
    <div class="admin-ticket-list">
        <?php foreach ($messages as $supportMessage): ?>
            <a class="admin-ticket" href="<?= url('/admin/support/' . (int) $supportMessage['id']) ?>">
                <div><h2>#<?= (int) $supportMessage['id'] ?> &middot; <?= e($supportMessage['subject']) ?></h2><div class="admin-ticket-details"><span><?= e($supportMessage['email']) ?></span><span><?= (int) $supportMessage['reply_count'] ?> repl<?= (int) $supportMessage['reply_count'] === 1 ? 'y' : 'ies' ?></span><span>Opened <?= e($supportMessage['created_at']) ?></span></div></div>
                <div class="admin-ticket-side"><span class="status-badge support-status-<?= e($supportMessage['status']) ?>"><?= e(ucfirst($supportMessage['status'])) ?></span><small><?= e($supportMessage['updated_at'] ?? $supportMessage['created_at']) ?></small><span class="btn btn-sm btn-outline admin-ticket-open">Open ticket &rarr;</span></div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
