<?php
$title = 'Manage Users';
// Shared listing styles (users-table, badges, hero) live in /assets/admin-shared.css.
// The list itself is the shared admin component: app/Core/AdminTable.php.
use App\Core\AdminTable;

$roles = $roles ?? \App\Core\Auth::ADMIN_ROLES;
$baseParams = [];
if (($search ?? '') !== '') $baseParams['q'] = $search;
if (($flag ?? '') !== '')   $baseParams['flag'] = $flag;
if (($status ?? '') !== '') $baseParams['status'] = $status;
if (($role ?? '') !== '')   $baseParams['role'] = $role;

$listUrl = url('/admin/users');
$sortBase = $listUrl;

echo AdminTable::shell([
    'title'    => 'Users',
    'count'    => count($users),
    'total'    => (int) ($totalUsers ?? count($users)),
    'countNote' => number_format((int) ($activeCount ?? 0)) . ' active, '
        . number_format((int) ($suspendedCount ?? 0)) . ' suspended',
    'filter'   => [
        'action'  => $listUrl,
        'search'  => [
            'name'        => 'q',
            'value'       => $search ?? '',
            'placeholder' => 'Search by email…',
        ],
        'filters' => [
            [
                'name'       => 'flag',
                'label'      => 'Flag',
                'value'      => $flag ?? '',
                'autosubmit' => true,
                'options'    => ['flagged' => 'Flagged only']
                    + array_combine(
                        ['chargeback', 'vip', 'watch', 'abuser', 'comped'],
                        array_map(static fn($p) => 'Flag: ' . $p, ['chargeback', 'vip', 'watch', 'abuser', 'comped'])
                    ),
            ],
            [
                'name'       => 'status',
                'label'      => 'Status',
                'value'      => $status ?? '',
                'autosubmit' => true,
                'options'    => ['' => 'All statuses', 'active' => 'Active', 'suspended' => 'Suspended'],
            ],
            [
                'name'       => 'role',
                'label'      => 'Role',
                'value'      => $role ?? '',
                'autosubmit' => true,
                'options'    => ['' => 'All roles']
                    + array_combine(
                        $roles,
                        array_map(static fn($r) => str_replace('_', ' ', $r), $roles)
                    ),
            ],
        ],
        'actions'  => '<button class="btn btn-sm" type="submit">Search</button>',
    ],
    'bulk'     => [
        'action'  => url('/admin/users/bulk'),
        'wrap'    => true,
        'id'      => 'users-bulk-form',
        'label'   => 'With selected:',
        'confirm' => 'Apply this action to the selected users?',
        'controls' =>
            '<select name="action" data-action="toggleBulkRole">'
            . '<option value="role">Set role</option>'
            . '<option value="suspend">Suspend</option>'
            . '<option value="activate">Reactivate</option>'
            . '<option value="delete">Delete</option>'
            . '</select>'
            . '<select name="role" id="bulk-role">'
            . implode('', array_map(
                static fn($r) => '<option value="' . e($r) . '">' . e(str_replace('_', ' ', $r)) . '</option>',
                $roles
            ))
            . (!in_array('user', $roles, true) ? '<option value="user">user</option>' : '')
            . '</select>'
            . '<button type="submit" class="btn btn-sm" data-action="bulkApply">Apply</button>',
    ],
    'columns'  => [
        ['label' => '<input type="checkbox" id="check-all" title="Select all">'],
        ['label' => AdminTable::sortLink('email', 'Email', $sortBy ?? '', $sortDir ?? '', $baseParams, $sortBase)],
        ['label' => AdminTable::sortLink('role', 'Role', $sortBy ?? '', $sortDir ?? '', $baseParams, $sortBase)],
        ['label' => AdminTable::sortLink('status', 'Status', $sortBy ?? '', $sortDir ?? '', $baseParams, $sortBase)],
        ['label' => 'Last login'],
        ['label' => 'Membership'],
        ['label' => AdminTable::sortLink('created_at', 'Created', $sortBy ?? '', $sortDir ?? '', $baseParams, $sortBase)],
        ['label' => 'Actions', 'class' => 'act'],
    ],
    'body'     => function () use ($users, $roles): void {
        ?>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?php if ((int) $user['id'] !== (int) \App\Core\Auth::user()['id']): ?><input type="checkbox" name="ids[]" value="<?= (int) $user['id'] ?>" class="user-check"><?php endif; ?></td>
                <td class="user-email"><?= e($user['email']) ?>
                    <?php if (!empty($user['flag'])): ?>
                        <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/flag') ?>" class="inline">
                            <?= csrf_field() ?>
                            <select name="flag" data-autosubmit class="adm-inline-select">
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
                    <form method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/role') ?>" class="inline">
                        <?= csrf_field() ?>
                        <select name="role" data-autosubmit class="adm-inline-select role-badge <?= e($user['role']) ?>">
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
                <td class="user-date"><?= !empty($user['last_login_at']) ? e(tzdate('Y-m-d', $user['last_login_at'])) : 'never' ?></td>
                <td>
                    <?php if (!empty($user['sub_status'])): ?>
                        <?= e($user['sub_plan']) ?> <span class="status-badge <?= e($user['sub_status']) ?>"><?= e($user['sub_status']) ?></span>
                    <?php else: ?>
                        <span class="muted">None</span>
                    <?php endif; ?>
                </td>
                <td class="user-date"><?= e(tzdate('Y-m-d', $user['created_at'])) ?></td>
                <td class="user-actions act">
                    <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id']) ?>">View</a>
                    <a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $user['id'] . '/edit') ?>">Edit</a>
                    <?php if ((int) $user['id'] !== (int) \App\Core\Auth::user()['id']): ?>
                        <form class="inline" method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/impersonate') ?>"
                              data-confirm="Sign in as <?= e($user['email']) ?>?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm">Login as</button>
                        </form>
                    <?php endif; ?>
                    <form class="inline" method="post" action="<?= url('/admin/users/' . (int) $user['id'] . '/delete') ?>"
                          data-confirm="Delete user <?= e($user['email']) ?>?">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php
    },
    'empty'    => 'No users yet.',
    'emptyAction' => '<a class="btn btn-sm" href="' . url('/admin/users/create') . '">Create a user</a>',
    'pager'    => [
        'base'   => $listUrl,
        'page'   => (int) ($page ?? 1),
        'pages'  => (int) ($totalPages ?? 1),
        'params' => $baseParams,
    ],
    'foot'     => '<a class="btn" href="' . url('/admin/users/create') . '">Add User</a>',
]);
?>
<script nonce="<?= csp_nonce() ?>">
(function () {
    var all = document.getElementById('check-all');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.user-check').forEach(function (c) { c.checked = all.checked; });
    });
})();
</script>
