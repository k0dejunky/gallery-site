<?php $title = 'Admin'; ?>

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
    <table>
        <thead>
            <tr>
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
    <?php $baseUrl = url('/admin'); require __DIR__ . '/../partials/pagination.php'; ?>
<?php endif; ?>
