<?php $title = 'Video List'; ?>

<div class="subhead">
    <div class="subhead-actions">
        <form method="get" action="<?= url('/admin/videos') ?>">
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search videos...">
        </form>
        <a class="btn" href="<?= url('/admin/video-projects') ?>">Video Projects</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Thumbnail</th>
                    <th>ID</th>
                    <th>Filename</th>
                    <th>Gallery</th>
                    <th>Views</th>
                    <th>Projects</th>
                    <th>Uploaded</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($videos)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No videos found.</td></tr>
                <?php else: ?>
                <?php foreach ($videos as $video): ?>
                <tr>
                    <td><img class="video-thumb" src="<?= e(file_url($video['filename'], 'thumb')) ?>" alt="Video thumbnail" loading="lazy" data-hide-on-error></td>
                    <td><?= (int) $video['id'] ?></td>
                    <td class="video-filename"><?= e($video['filename']) ?></td>
                    <td>
                        <?php if (!empty($video['gallery_title'])): ?>
                            <?= e($video['gallery_title']) ?>
                        <?php else: ?>
                            <span class="muted">Orphaned</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format((int) $video['views']) ?></td>
                    <td><?= (int) $video['project_count'] ?></td>
                    <td class="video-meta"><?= e(tzdate('Y-m-d H:i', $video['created_at'])) ?></td>
                    <td>
                        <a class="btn small" href="<?= url('/admin/videos/' . (int) $video['id'] . '/edit') ?>">Open Video Editor</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
