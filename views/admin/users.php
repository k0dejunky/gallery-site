<?php $title = 'Manage Users'; ?>
<style>
  .users-hero{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;margin-bottom:1.25rem}
  .users-hero h1{margin-bottom:.25rem}.users-hero p{margin:0;color:var(--muted-text-color)}
  .users-table-wrap{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--card-radius);overflow:auto;box-shadow:var(--shadow)}
  .users-table{margin:0;width:100%;table-layout:fixed}.users-table th{white-space:nowrap}.users-table td{vertical-align:middle;overflow-wrap:anywhere}
  .user-email{font-weight:700;color:var(--heading-color);word-break:break-word}.user-date{white-space:normal;color:var(--muted-text-color);font-size:.85rem}
  .role-badge,.status-badge{display:inline-block;padding:.2rem .5rem;border-radius:999px;font-size:.72rem;font-weight:700;text-transform:capitalize}
  .role-badge{background:var(--filter-bg);color:var(--filter-text);border:1px solid var(--filter-border)}
  .role-badge.admin,.role-badge.super_admin{background:var(--sidebar-active-bg);color:var(--sidebar-active-color)}
  .status-badge{background:var(--success-bg);color:var(--success-text)}
  .status-badge.pending{background:var(--warning-bg);color:var(--warning-text)}
  .status-badge.cancelled,.status-badge.expired{background:var(--danger-bg);color:var(--danger-text)}
  .user-actions{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap}.user-actions form{margin:0}
  .users-footer{margin-top:1rem;display:flex;justify-content:flex-end}
  @media(max-width:760px){.users-hero{display:block}.users-table{font-size:.82rem}.users-table th,.users-table td{padding:.45rem .35rem}.user-actions .btn{padding:.35rem .45rem}.users-footer{justify-content:stretch}.users-footer .btn{width:100%;text-align:center}}
</style>

<?php // List existing accounts and link to the create-user page. ?>
<div class="users-hero">
    <div><h1>Manage Users</h1><p>Manage accounts, roles, memberships, and access.</p></div>
</div>

<h2 class="section-title">Users</h2>
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
                    <td class="user-email"><?= e($user['email']) ?></td>
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
