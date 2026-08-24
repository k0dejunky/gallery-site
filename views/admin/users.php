<?php $title = 'Manage Users'; ?>
<?php // Shared listing styles (users-table, badges, hero) live in /assets/admin-shared.css. ?>

<?php // List existing accounts and link to the create-user page. ?>
<div class="users-hero">
    <div><h1>Manage Users</h1><p>Manage accounts, roles, memberships, and access.</p></div>
</div>

<h2 class="section-title">Users</h2>
<form method="get" action="<?= url('/admin/users') ?>" style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
    <label class="muted" for="flag-filter">Show:</label>
    <select id="flag-filter" name="flag" onchange="this.form.submit();">
        <option value="">All users</option>
        <option value="flagged"<?= ($flag ?? '') === 'flagged' ? ' selected' : '' ?>>Flagged only</option>
        <?php foreach (['chargeback', 'vip', 'watch', 'abuser', 'comped'] as $preset): ?>
            <option value="<?= e($preset) ?>"<?= ($flag ?? '') === $preset ? ' selected' : '' ?>>Flag: <?= e($preset) ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-sm" type="submit">Apply</button></noscript>
</form>
<?php if (($flag ?? '') !== ''): ?>
    <p class="muted">Filtered by flag: <b><?= e($flag) ?></b> — <a href="<?= url('/admin/users') ?>">clear</a></p>
<?php endif; ?>
<?php if (empty($users)): ?>
    <p>No users yet.</p>
<?php else: ?>
<form method="post" action="<?= url('/admin/users/bulk') ?>" id="users-bulk-form">
    <?= csrf_field() ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:.5rem;">
        <strong>With selected:</strong>
        <select name="action" onchange="document.getElementById('bulk-role').style.display = (this.value === 'role') ? '' : 'none';">
            <option value="role">Set role</option>
            <option value="suspend">Suspend</option>
            <option value="activate">Reactivate</option>
            <option value="delete">Delete</option>
        </select>
        <select name="role" id="bulk-role">
            <?php foreach ($roles as $role): ?>
                <option value="<?= e($role) ?>"><?= e(str_replace('_', ' ', $role)) ?></option>
            <?php endforeach; ?>
            <option value="user">user</option>
        </select>
        <button type="submit" class="btn btn-sm"
                onclick="return confirm('Apply bulk action to all checked users?');">Apply</button>
    </div>
    <div class="users-table-wrap"><table class="users-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="check-all" title="Select all"></th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Membership</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php if ((int) $user['id'] !== (int) \App\Core\Auth::user()['id']): ?><input type="checkbox" name="ids[]" value="<?= (int) $user['id'] ?>" class="user-check"><?php endif; ?></td>
                    <td class="user-email"><?= e($user['email']) ?>
                        <?php if (!empty($user['flag'])): ?><span class="badge-flag"><?= e($user['flag']) ?></span><?php endif; ?>
                    </td>
                    <td><span class="role-badge <?= e($user['role']) ?>"><?= e(str_replace('_', ' ', $user['role'])) ?></span></td>
                    <td>
                        <?php if (($user['status'] ?? 'active') === 'suspended'): ?>
                            <span class="status-badge cancelled">suspended</span>
                        <?php else: ?>
                            <span class="status-badge">active</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($user['sub_status'])): ?>
                            <?= e($user['sub_plan']) ?> <span class="status-badge <?= e($user['sub_status']) ?>"><?= e($user['sub_status']) ?></span>
                        <?php else: ?>
                            <span class="muted">None</span>
                        <?php endif; ?>
                    </td>
                    <td class="user-date"><?= e($user['created_at']) ?></td>
                    <td class="user-actions">
                        <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id']) ?>">View</a>
                        <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id'] . '/edit') ?>">Edit</a>
                        <?php if ((int) $user['id'] !== (int) \App\Core\Auth::user()['id']): ?>
                            <form class="inline" method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/impersonate') ?>"
                                  onsubmit="return confirm('Sign in as <?= e($user['email']) ?>?');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Login as</button>
                            </form>
                        <?php endif; ?>
                        <form class="inline" method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/delete') ?>"
                              onsubmit="return confirm('Delete user <?= e($user['email']) ?>?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</form>
<script>
(function () {
    var all = document.getElementById('check-all');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.user-check').forEach(function (c) { c.checked = all.checked; });
    });
})();
</script>
<?php endif; ?>
<div class="users-footer">
    <a class="btn" href="<?= url('/admin/users/create') ?>">Add User</a>
</div>
