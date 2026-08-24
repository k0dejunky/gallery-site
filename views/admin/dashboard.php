<?php $title = 'Admin'; ?>

<?php // Membership growth cards: recurring revenue + recent signups. ?>
<div class="stat-cards">
    <div class="stat-card"><b>$<?= number_format((float) $growth['mrr'], 2) ?></b><small>Recurring / month</small></div>
    <div class="stat-card"><b><?= number_format($growth['new_today']) ?></b><small>Signups Today</small></div>
    <div class="stat-card"><b><?= number_format($growth['new_week']) ?></b><small>Signups 7 Days</small></div>
    <?php foreach (array_slice($growth['by_processor'], 0, 2) as $proc): ?>
        <div class="stat-card"><b><?= number_format((int) $proc['members']) ?> · $<?= number_format((float) $proc['mrr'], 0) ?>/mo</b><small>via <?= e((string) $proc['name']) ?></small></div>
    <?php endforeach; ?>
</div>

<?php // Headline stat cards: lifetime totals for the whole site. ?>
<div class="stat-cards">
    <div class="stat-card"><b><?= number_format($summary['total_views']) ?></b><small>Total Views</small></div>
    <div class="stat-card"><b><?= number_format($summary['photos']) ?></b><small>Photos</small></div>
    <div class="stat-card"><b><?= number_format($summary['videos']) ?></b><small>Videos</small></div>
    <div class="stat-card"><b><?= number_format($summary['galleries']) ?></b><small>Galleries</small></div>
    <div class="stat-card"><b><?= number_format($summary['total_members']) ?></b><small>Members</small></div>
    <div class="stat-card"><b><?= number_format($summary['total_users']) ?></b><small>Users</small></div>
    <div class="stat-card"><b><?= number_format($summary['logged_in_members']) ?></b><small>Logged In Members</small></div>
</div>

<?php if (empty($paginator['items'])): ?>
    <p>No galleries yet.</p>
<?php else: ?>
<form method="post" action="<?= url('/admin/galleries/bulk') ?>">
    <?= csrf_field() ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.75rem 0;">
        <strong>With selected:</strong>
        <select name="action" onchange="document.getElementById('bulk-cat').style.display = this.value === 'category' ? '' : 'none';">
            <option value="delete">Delete</option>
            <option value="category">Set category</option>
        </select>
        <select name="category_id" id="bulk-cat" style="display:none;">
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm" onclick="return confirm('Apply bulk action to all checked galleries?');">Apply</button>
    </div>
    <table>
        <thead>
            <tr>
                <th><input type="checkbox" id="gallery-check-all" title="Select all"></th>
                <th>Title</th>
                <th>Photos</th>
                <th>Total Views</th>
                <th>Unique Views</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($paginator['items'] as $gallery): ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int) $gallery['id'] ?>" class="gallery-check"></td>
                    <td><?= e($gallery['title']) ?></td>
                    <td><?= (int) $gallery['photo_count'] ?></td>
                    <td><?= number_format((int) $gallery['views']) ?></td>
                    <td><?= number_format((int) $gallery['unique_views']) ?></td>
                    <td><?= e($gallery['created_at']) ?></td>
                    <td>
                        <?php // Manage = photo controls; Delete = confirm + remove. ?>
                        <a class="btn btn-sm" href="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">Manage</a>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/delete') ?>"
                              onsubmit="return confirm('Delete this gallery and orphaned photos?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</form>
<script>
(function () {
    var all = document.getElementById('gallery-check-all');
    if (!all) return;
    all.addEventListener('change', function () {
        document.querySelectorAll('.gallery-check').forEach(function (c) { c.checked = all.checked; });
    });
})();
</script>
<?php $baseUrl = url('/admin'); require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
