<?php $title = 'User Monitor'; ?>

<h1>User Monitor</h1>
<p class="muted">When members log in, log out, and which galleries they are viewing. Each row is one event.</p>

<?php if (!empty($lastSeen)): ?>
    <h2>Active Users</h2>
    <p class="muted">Most recent activity per member.</p>
    <table>
        <thead><tr><th>User</th><th>Last Action</th><th>Gallery</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($lastSeen as $ev): ?>
            <tr>
                <td><?= e($ev['user_email']) ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $ev['action']))) ?></td>
                <td>
                    <?php if ($ev['gallery_id']): ?>
                        <a href="<?= url('/admin/user-monitor?user=' . (int) $ev['user_id']) ?>"><?= e($ev['gallery_name'] ?: 'Gallery #' . $ev['gallery_id']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= e($ev['ip'] ?? '—') ?></td>
                <td><?= e($ev['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Activity Feed</h2>

<form method="get" action="<?= url('/admin/user-monitor') ?>" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:end;margin-bottom:.75rem;">
    <label>Search<br><input type="text" name="q" value="<?= e($filterQ) ?>" placeholder="email or gallery name" size="28"></label>
    <label>Action<br>
        <select name="action">
            <option value="">— any —</option>
            <?php foreach (($facets['actions'] ?? []) as $a): ?>
                <option value="<?= e((string) $a) ?>" <?= $filterAction === (string) $a ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst((string) $a))) ?></option>
            <?php endforeach; ?>
        </select></label>
    <label>User<br>
        <select name="user">
            <option value="">— all —</option>
            <?php foreach (\App\Models\UserActivity::lastSeenByUser(500) as $u): ?>
                <option value="<?= (int) $u['user_id'] ?>" <?= $filterUserId === (int) $u['user_id'] ? 'selected' : '' ?>><?= e($u['user_email']) ?></option>
            <?php endforeach; ?>
        </select></label>
    <button type="submit" class="btn btn-sm">Filter</button>
    <?php if ($filterQ !== '' || $filterAction !== '' || $filterUserId > 0): ?><a class="btn btn-sm" href="<?= url('/admin/user-monitor') ?>">Clear</a><?php endif; ?>
</form>

<?php if (empty($paginator['items'])): ?>
    <p>No activity recorded yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Gallery</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($paginator['items'] as $ev): ?>
            <tr>
                <td><?= e($ev['created_at']) ?></td>
                <td><?= e($ev['user_email']) ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $ev['action']))) ?></td>
                <td><?= e($ev['gallery_name'] ?: '—') ?></td>
                <td><?= e($ev['ip'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $query = [];
    if ($filterQ !== '') $query['q'] = $filterQ;
    if ($filterAction !== '') $query['action'] = $filterAction;
    if ($filterUserId > 0) $query['user'] = $filterUserId;
    $baseUrl = url('/admin/user-monitor') . ($query ? '?' . http_build_query($query) : '');
    require __DIR__ . '/../partials/pagination.php';
    ?>
<?php endif; ?>
