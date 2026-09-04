<?php $title = 'Video List'; ?>
<style>
    .subhead{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:18px;flex-wrap:wrap}
    .subhead h1{margin:0}
    .subhead-actions{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
    .subhead-actions form{margin:0}
    .subhead-actions input[type="search"]{padding:.4rem .6rem;border-radius:var(--border-radius-sm);border:1px solid var(--filter-border);background:var(--filter-bg);color:var(--filter-text)}
    .video-filename{font-weight:700;color:var(--heading-color);word-break:break-word}
    .video-meta{color:var(--muted-text-color);font-size:.85rem}
    .video-thumb{display:block;width:112px;height:64px;object-fit:cover;border-radius:6px;background:var(--card-thumb-bg,#24152d)}
    @media(max-width:600px){.subhead{display:block}.subhead-actions{margin-top:.75rem}.subhead-actions .btn{flex:1;text-align:center}}
</style>

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
                    <td><img class="video-thumb" src="<?= e(file_url($video['filename'], 'thumb')) ?>" alt="Video thumbnail" loading="lazy" onerror="this.style.display='none'"></td>
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
                    <td class="video-meta"><?= e($video['created_at']) ?></td>
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
