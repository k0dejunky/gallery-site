<?php $title = 'Manage Galleries'; ?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
    <div>
        <h1 style="margin:0;">Manage Galleries</h1>
        <p class="muted" style="margin:.25rem 0 0;">Every gallery on the site, with quick access to its contents, settings, and deletion.</p>
    </div>
    <a class="btn btn-sm" href="<?= url('/admin/galleries/create') ?>">New Gallery</a>
</div>

<?php if (empty($galleries)): ?>
    <p>No galleries yet. <a href="<?= url('/admin/galleries/create') ?>">Create your first gallery.</a></p>
<?php else: ?>
    <p class="muted" style="margin-bottom:.5rem;"><?= number_format(count($galleries)) ?> gallery(ies)</p>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Media</th>
                <th>Level</th>
                <th>Categories</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($galleries as $gallery): ?>
                <?php $gid = (int) $gallery['id']; ?>
                <tr>
                    <td><?= e((string) $gallery['title']) ?></td>
                    <td>
                        <?php if (($gallery['type'] ?? 'images') === 'videos'): ?>
                            <span class="pill pill-info">Videos</span>
                        <?php else: ?>
                            <span class="pill pill-muted">Images</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= number_format((int) ($gallery['photo_count'] ?? 0)) ?> image(s)
                        &middot; <?= number_format((int) ($gallery['video_count'] ?? 0)) ?> video(s)
                    </td>
                    <td><?= (int) ($gallery['min_level'] ?? 0) > 0 ? 'L' . (int) $gallery['min_level'] : 'All' ?></td>
                    <td>
                        <?php $gs = $categories[$gid] ?? []; ?>
                        <?php if ($gs === []): ?>
                            <span class="muted">Uncategorized</span>
                        <?php else: ?>
                            <?php foreach ($gs as $cat): ?>
                                <span class="pill"><?= e((string) $cat['name']) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($gallery['created_at'] ?? '')) ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn-sm" href="<?= url('/admin/galleries/' . $gid) ?>">Manage</a>
                        <a class="btn btn-sm" href="<?= url('/admin/galleries/' . $gid . '/edit') ?>">Edit</a>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . $gid . '/delete') ?>"
                              onsubmit="return confirm('Delete gallery &quot;<?= e((string) $gallery['title']) ?>&quot;?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
