<?php $title = 'Search'; ?>

<?php if (mb_strlen($q) < 2): ?>
    <p class="muted">Type at least two characters in the search bar above.</p>
<?php else: ?>
    <?php $any = false; ?>

    <?php if (!empty($users)): $any = true; ?>
        <div class="sys-card">
            <h2>Users</h2>
            <table>
                <thead><tr><th>ID</th><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= (int) $u['id'] ?></td>
                        <td><?= e($u['email']) ?><?= !empty($u['flag']) ? ' <span class="badge badge-flag">' . e($u['flag']) . '</span>' : '' ?></td>
                        <td><?= e($u['role']) ?></td>
                        <td><?= e($u['status'] ?? 'active') ?></td>
                        <td><a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $u['id']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($galleries)): $any = true; ?>
        <div class="sys-card">
            <h2>Galleries</h2>
            <table>
                <thead><tr><th>ID</th><th>Title</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($galleries as $g): ?>
                    <tr>
                        <td><?= (int) $g['id'] ?></td>
                        <td><?= e($g['title']) ?></td>
                        <td><?= e($g['created_at']) ?></td>
                        <td><a class="btn btn-sm" href="<?= url('/admin/galleries/' . (int) $g['id']) ?>">Manage</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($transactions)): $any = true; ?>
        <div class="sys-card">
            <h2>Transactions</h2>
            <table>
                <thead><tr><th>ID</th><th>Reference</th><th>Status</th><th>User</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= (int) $t['id'] ?></td>
                        <td><code><?= e((string) $t['transaction_ref']) ?></code></td>
                        <td><?= e($t['status']) ?></td>
                        <td><?= e((string) ($t['email'] ?? '#' . $t['user_id'])) ?></td>
                        <td><a class="btn btn-sm" href="<?= url('/admin/users/' . (int) $t['user_id']) ?>">Member</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($photos)): $any = true; ?>
        <div class="sys-card">
            <h2>Photos</h2>
            <table>
                <thead><tr><th>ID</th><th>Filename</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($photos as $p): ?>
                    <tr>
                        <td><?= (int) $p['id'] ?></td>
                        <td><code><?= e($p['filename']) ?></code></td>
                        <td>
                            <?php if (!empty($p['gallery_id'])): ?>
                                <a class="btn btn-sm" href="<?= url('/admin/galleries/' . (int) $p['gallery_id']) ?>">Gallery</a>
                            <?php endif; ?>
                            <a class="btn btn-sm" href="<?= url('/admin/photos/' . (int) $p['id'] . '/edit') ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!$any): ?>
        <p>No matches for “<?= e($q) ?>”.</p>
    <?php endif; ?>
<?php endif; ?>
