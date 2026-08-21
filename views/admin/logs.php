<?php $title = 'Admin Logs'; ?>

<h1>Admin Logs</h1>

<?php if (!empty($pendingSubs)): ?>
<h2>Pending Memberships</h2>
<table>
    <thead><tr><th>When</th><th>User</th><th>Plan</th><th>Price</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($pendingSubs as $sub): ?>
        <tr>
            <td><?= e($sub['created_at']) ?></td>
            <td><?= e($sub['user_email']) ?></td>
            <td><?= e($sub['plan_name']) ?></td>
            <td>$<?= number_format((float) $sub['price'], 2) ?> / <?= e(\App\Models\Plan::cycleLabel($sub['billing_cycle'])) ?></td>
            <td>
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/approve') ?>" onsubmit="return confirm('Approve this membership?');">
                    <?= csrf_field() ?><input type="hidden" name="return_to" value="logs"><button type="submit" class="btn btn-sm">Approve</button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/subscriptions/' . (int) $sub['id'] . '/delete') ?>" onsubmit="return confirm('Deny and delete this request?');">
                    <?= csrf_field() ?><input type="hidden" name="return_to" value="logs"><button type="submit" class="btn btn-sm btn-danger">Deny</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!empty($pendingDeletes)): ?>
<h2>Deleted Galleries</h2>
<p class="muted">Galleries that have been deleted but not restored or permanently purged.</p>
<table>
    <thead><tr><th>When</th><th>Admin</th><th>Gallery</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($pendingDeletes as $log): ?>
        <tr>
            <td><?= e($log['created_at']) ?></td>
            <td><?= e($log['admin_email'] ?? 'Unknown') ?></td>
            <td><?= e($log['description']) ?></td>
            <td>
                <form class="inline" method="post" action="<?= url('/admin/logs/' . (int) $log['id'] . '/rollback') ?>" onsubmit="return confirm('Restore this gallery?');">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm">Restore</button>
                </form>
                <form class="inline" method="post" action="<?= url('/admin/logs/' . (int) $log['id'] . '/purge') ?>" onsubmit="return confirm('Permanently delete this? Cannot be undone.');">
                    <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Purge</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!empty($pendingApprovals)): ?>
<h2>Pending Category Approvals</h2>
<p class="muted">Frequently missed searches that can be promoted to categories.</p>
<table>
    <thead><tr><th>Search Term</th><th>Times Searched</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($pendingApprovals as $term): ?>
        <tr>
            <td><?= e($term['term']) ?></td>
            <td><?= number_format($term['count']) ?></td>
            <td>
                <form class="inline" method="post" action="<?= url('/admin/trends/promote') ?>" onsubmit="return confirm('Create category "<?= e($term['term']) ?>" from this search term?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="term" value="<?= e($term['term']) ?>">
                    <button type="submit" class="btn btn-sm">Approve</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Activity Log</h2>
<p class="muted">All admin actions are recorded. Create, update and delete actions can be rolled back where data allows. Passwords are never stored.</p>

<?php if (empty($paginator['items'])): ?>
    <p>No admin actions recorded yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Details</th><th>Rollback</th></tr></thead>
        <tbody>
        <?php foreach ($paginator['items'] as $log): ?>
            <?php
            $isRolledBack = $log['rolled_back_at'] !== null;
            $action       = $log['action'];
            $entityType   = $log['entity_type'];
            $entityId     = (int) ($log['entity_id'] ?? 0);
            $canRollback  = !$isRolledBack && $action !== 'rollback' && $action !== 'purge' && $action !== 'restore';

            // Photo rotate/thumb/original are lossy — cannot undo.
            if ($canRollback && $entityType === 'photo' && in_array($action, ['update'], true)) {
                $before = json_decode($log['before_json'] ?? 'null', true);
                if (is_array($before) && array_key_exists('filename', $before) && !array_key_exists('caption', $before)) {
                    $canRollback = false;
                }
            }

            // Photo upload (create with photo_count) — not entity-based rollback.
            if ($canRollback && $entityType === 'photo' && $action === 'create' && $entityId === 0) {
                $canRollback = false;
            }
            ?>
            <tr>
                <td><?= e($log['created_at']) ?></td>
                <td><?= e($log['admin_email'] ?? 'Unknown') ?></td>
                <td><?= e($action) ?> <small>(<?= e($entityType) ?><?= $entityId ? ' #' . $entityId : '' ?>)</small></td>
                <td>
                    <?= e($log['description']) ?>
                    <?php if (!empty($log['changes'])): ?>
                        <div class="log-diff">
                            <?php foreach ($log['changes'] as $change): ?>
                                <div class="log-diff-line">
                                    <span class="diff-field"><?= e($change['field']) ?>:</span>
                                    <span class="diff-before"><?= e($change['before']) ?></span>
                                    <span class="diff-arrow">&rarr;</span>
                                    <span class="diff-after"><?= e($change['after']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($isRolledBack): ?>
                        <br><span class="muted">Rolled back <?= e($log['rolled_back_at']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canRollback && $entityType === 'gallery' && $action === 'delete'): ?>
                        <form class="inline" method="post" action="<?= url('/admin/logs/' . (int) $log['id'] . '/rollback') ?>" onsubmit="return confirm('Restore this gallery?');">
                            <?= csrf_field() ?><button type="submit" class="btn btn-sm">Restore</button>
                        </form>
                        <form class="inline" method="post" action="<?= url('/admin/logs/' . (int) $log['id'] . '/purge') ?>" onsubmit="return confirm('Permanently delete this gallery and its files? This cannot be undone.');">
                            <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Delete permanently</button>
                        </form>
                    <?php elseif ($canRollback): ?>
                        <form class="inline" method="post" action="<?= url('/admin/logs/' . (int) $log['id'] . '/rollback') ?>" onsubmit="return confirm('Roll back this admin change?');">
                            <?= csrf_field() ?><button type="submit" class="btn btn-sm btn-danger">Rollback</button>
                        </form>
                    <?php else: ?>
                        <span class="muted"><?= $isRolledBack ? 'Rolled back' : 'Not reversible' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php $baseUrl = url('/admin/logs'); require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
