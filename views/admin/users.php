<?php $title = 'Manage Users'; ?>
<?php // Shared listing styles (users-table, badges, hero) live in /assets/admin-shared.css. ?>

<?php // List existing accounts and link to the create-user page. ?>
<h2 class="section-title">Users</h2>
<form method="get" action="<?= url('/admin/users') ?>" style="display:flex;gap:.5rem;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;">
    <input type="text" name="q" value="<?= e($search ?? '') ?>" placeholder="Search by email…" style="flex:1;min-width:200px;padding:.4rem .6rem;border:1px solid var(--border,#e5e7eb);border-radius:var(--border-radius);">
    <label class="muted" for="flag-filter">Flag:</label>
    <select id="flag-filter" name="flag" onchange="this.form.submit();">
        <option value="">All flags</option>
        <option value="flagged"<?= ($flag ?? '') === 'flagged' ? ' selected' : '' ?>>Flagged only</option>
        <?php foreach (['chargeback', 'vip', 'watch', 'abuser', 'comped'] as $preset): ?>
            <option value="<?= e($preset) ?>"<?= ($flag ?? '') === $preset ? ' selected' : '' ?>>Flag: <?= e($preset) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="muted" for="status-filter">Status:</label>
    <select id="status-filter" name="status" onchange="this.form.submit();">
        <option value="">All statuses</option>
        <option value="active"<?= ($status ?? '') === 'active' ? ' selected' : '' ?>>Active</option>
        <option value="suspended"<?= ($status ?? '') === 'suspended' ? ' selected' : '' ?>>Suspended</option>
    </select>
    <label class="muted" for="role-filter">Role:</label>
    <select id="role-filter" name="role" onchange="this.form.submit();">
        <option value="">All roles</option>
        <?php foreach ($roles as $r): ?>
            <option value="<?= e($r) ?>"<?= ($role ?? '') === $r ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $r)) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-sm" type="submit">Search</button>
</form>

<p class="muted" style="margin-bottom:.5rem;">Showing <?= number_format(count($users)) ?> of <?= number_format($totalUsers) ?> users (<?= number_format($activeCount) ?> active, <?= number_format($suspendedCount) ?> suspended)</p>

<?php
    $baseParams = [];
    if (($search ?? '') !== '') $baseParams['q'] = $search;
    if (($flag ?? '') !== '')   $baseParams['flag'] = $flag;
    if (($status ?? '') !== '') $baseParams['status'] = $status;
    if (($role ?? '') !== '')   $baseParams['role'] = $role;

    function _sortLink(string $col, string $label, string $sortBy, string $sortDir, array $baseParams): string {
        $newDir = ($sortBy === $col && $sortDir === 'ASC') ? 'DESC' : 'ASC';
        $params = array_merge($baseParams, ['sort' => $col, 'dir' => $newDir]);
        $arrow = '';
        if ($sortBy === $col) {
            $arrow = $sortDir === 'ASC' ? ' ▲' : ' ▼';
        }
        return '<a href="?' . http_build_query($params) . '">' . e($label) . $arrow . '</a>';
    }
?>

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
            <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>"><?= e(str_replace('_', ' ', $r)) ?></option>
            <?php endforeach; ?>
            <?php if (!in_array('user', $roles, true)): ?>
                <option value="user">user</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn btn-sm"
                onclick="var c=document.querySelectorAll('.user-check:checked').length; if(!c){alert('No users selected.');return false;} if(!confirm('Apply bulk action to '+c+' checked user(s)?'))return false;">Apply</button>
    </div>
    <div class="users-table-wrap"><table class="users-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="check-all" title="Select all"></th>
                <th><?= _sortLink('email', 'Email', $sortBy, $sortDir, $baseParams) ?></th>
                <th><?= _sortLink('role', 'Role', $sortBy, $sortDir, $baseParams) ?></th>
                <th><?= _sortLink('status', 'Status', $sortBy, $sortDir, $baseParams) ?></th>
                <th>Last login</th>
                <th>Membership</th>
                <th><?= _sortLink('created_at', 'Created', $sortBy, $sortDir, $baseParams) ?></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php if ((int) $user['id'] !== (int) \App\Core\Auth::user()['id']): ?><input type="checkbox" name="ids[]" value="<?= (int) $user['id'] ?>" class="user-check"><?php endif; ?></td>
                    <td class="user-email"><?= e($user['email']) ?>
                        <?php if (!empty($user['flag'])): ?>
                            <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/flag') ?>" class="inline" style="display:inline;">
                                <?= csrf_field() ?>
                                <select name="flag" onchange="this.form.submit();" style="font-size:.75rem;padding:1px 3px;border:1px solid var(--border,#e5e7eb);border-radius:4px;background:transparent;cursor:pointer;">
                                    <option value="">none</option>
                                    <?php foreach (['chargeback', 'vip', 'watch', 'abuser', 'comped'] as $fp): ?>
                                        <option value="<?= e($fp) ?>"<?= ($user['flag'] ?? '') === $fp ? ' selected' : '' ?>><?= e($fp) ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!empty($user['flag']) && !in_array($user['flag'], ['', 'chargeback', 'vip', 'watch', 'abuser', 'comped'], true)): ?>
                                        <option value="<?= e($user['flag']) ?>" selected><?= e($user['flag']) ?> (custom)</option>
                                    <?php endif; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/role') ?>" class="inline" style="display:inline;">
                            <?= csrf_field() ?>
                            <select name="role" onchange="this.form.submit();" style="font-size:.75rem;padding:1px 3px;border:1px solid var(--border,#e5e7eb);border-radius:4px;background:transparent;cursor:pointer;" class="role-badge <?= e($user['role']) ?>">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= e($r) ?>"<?= $user['role'] === $r ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $r)) ?></option>
                                <?php endforeach; ?>
                                <?php if (!in_array('user', $roles, true)): ?>
                                    <option value="user"<?= $user['role'] === 'user' ? ' selected' : '' ?>>user</option>
                                <?php endif; ?>
                            </select>
                        </form>
                    </td>
                    <td>
                        <?php if (($user['status'] ?? 'active') === 'suspended'): ?>
                            <span class="status-badge cancelled">suspended</span>
                        <?php else: ?>
                            <span class="status-badge">active</span>
                        <?php endif; ?>
                    </td>
                    <td class="user-date"><?= e($user['last_login_at'] ?? 'never') ?></td>
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

<?php if (($totalPages ?? 1) > 1): ?>
<?php
    $pgParams = $baseParams;
    $currentPage = $page ?? 1;
?>
<div class="users-pagination" style="display:flex;align-items:center;gap:.5rem;margin:1rem 0;flex-wrap:wrap;">
    <?php if ($currentPage > 1): ?>
        <?php $pgParams['page'] = $currentPage - 1; ?>
        <a href="?<?= http_build_query($pgParams) ?>" class="btn btn-sm">&larr; Prev</a>
    <?php endif; ?>
    <?php
        $startPage = max(1, $currentPage - 2);
        $endPage   = min($totalPages, $currentPage + 2);
        if ($startPage > 1): ?>
            <?php $pgParams['page'] = 1; ?>
            <a href="?<?= http_build_query($pgParams) ?>" class="btn btn-sm">1</a>
            <?php if ($startPage > 2): ?><span class="muted">…</span><?php endif; ?>
        <?php endif;
        for ($i = $startPage; $i <= $endPage; $i++):
            $pgParams['page'] = $i;
        ?>
            <?php if ($i === $currentPage): ?>
                <span class="btn btn-sm" style="opacity:1;font-weight:700;"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query($pgParams) ?>" class="btn btn-sm"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor;
        if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?><span class="muted">…</span><?php endif; ?>
            <?php $pgParams['page'] = $totalPages; ?>
            <a href="?<?= http_build_query($pgParams) ?>" class="btn btn-sm"><?= $totalPages ?></a>
        <?php endif; ?>
    <span class="muted">Page <?= $currentPage ?> of <?= $totalPages ?></span>
    <?php if ($currentPage < $totalPages): ?>
        <?php $pgParams['page'] = $currentPage + 1; ?>
        <a href="?<?= http_build_query($pgParams) ?>" class="btn btn-sm">Next &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="users-footer">
    <a class="btn" href="<?= url('/admin/users/create') ?>">Add User</a>
</div>
