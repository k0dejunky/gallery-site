<style>
.subhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.subhead h1{margin:0}
.ve-export-preview{width:180px;max-width:18vw;aspect-ratio:16/9;background:#111;border-radius:6px;display:block}.ve-source-thumb{width:72px;height:48px;object-fit:cover;border-radius:5px;cursor:zoom-in;background:#eee}.ve-thumb-link{display:inline-block}.ve-preview-dialog{border:0;border-radius:10px;padding:0;background:rgba(10,10,15,.94);max-width:min(92vw,1100px);max-height:92vh}.ve-preview-dialog::backdrop{background:rgba(0,0,0,.72)}.ve-preview-dialog img{display:block;max-width:88vw;max-height:84vh}.ve-preview-close{position:absolute;right:8px;top:5px;border:0;background:rgba(0,0,0,.65);color:#fff;border-radius:50%;width:30px;height:30px;font-size:20px;cursor:pointer}
</style>
<div class="subhead">
    <h1>Video Projects</h1>
    <a class="btn" href="<?= url('/admin/videos') ?>">Video list</a>
</div>
<?php
$exportStatusLabel = static function (string $status): string {
    $map = ['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed'];
    return $map[$status] ?? ucfirst($status);
};
$formatBytes = static function (?int $bytes): string {
    if ($bytes === null || $bytes < 0) return '-';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
};
?>
<div class="subhead" style="margin-top:28px"><h2>Exported files</h2></div>
<div class="card">
    <div class="table-responsive">
        <table>
            <thead><tr><th>ID</th><th>Project</th><th>User</th><th>Preview</th><th>Status</th><th>Output file</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
                <?php if (empty($exports)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--text-muted)">No exports yet.</td></tr>
                <?php else: foreach ($exports as $ex): ?>
                <tr>
                    <td><?= (int) $ex['id'] ?></td>
                    <td><?= htmlspecialchars((string) ($ex['project_title'] ?? '-')) ?> <span class="text-muted">#<?= (int) $ex['project_id'] ?></span></td>
                    <td><?= htmlspecialchars((string) ($ex['user_email'] ?? '-')) ?></td>
                    <td><?php if ($ex['status'] === 'completed' && !empty($ex['output_file']) && !empty($ex['file_exists'])): ?><video class="ve-export-preview" controls preload="none" src="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/stream') ?>"></video><?php else: ?>-<?php endif; ?></td>
                    <td><span style="color:<?= $ex['status'] === 'completed' ? 'var(--success,#2e7d32)' : ($ex['status'] === 'failed' ? 'var(--danger,#c62828)' : 'inherit') ?>"><?= $exportStatusLabel((string) $ex['status']) ?></span><?php if ((int) ($ex['progress'] ?? 0) > 0 && in_array($ex['status'], ['queued', 'running'], true)): ?> (<?= (int) $ex['progress'] ?>%)<?php endif; ?></td>
                    <td><?= htmlspecialchars((string) ($ex['output_file'] ?? '-')) ?></td>
                    <td><?= $formatBytes(isset($ex['file_size']) ? (int) $ex['file_size'] : null) ?></td>
                    <td><?= htmlspecialchars((string) ($ex['created_at'] ?? '')) ?></td>
                    <td>
                        <?php if ($ex['status'] === 'completed' && !empty($ex['output_file']) && !empty($ex['file_exists'])): ?>
                        <a class="btn small" href="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/download') ?>">Download</a>
                        <a class="btn small" href="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/create-gallery') ?>">Create Gallery</a>
                        <?php elseif ($ex['status'] === 'failed' && !empty($ex['error'])): ?><span class="text-muted" title="<?= htmlspecialchars((string) $ex['error']) ?>">Error</span><?php endif; ?>
                        <?php if (in_array($ex['status'], ['completed', 'failed'], true)): ?>
                        <form method="post" action="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/delete') ?>" style="display:inline" onsubmit="return confirm('Delete this exported file?');">
                            <?= csrf_field() ?><button class="btn small" type="submit" style="background:#b42318;color:#fff">Delete</button>
                        </form>
                        <?php endif; ?>
                        <?php if (!empty($ex['gallery_exists'])): ?>
                        <form method="post" action="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/purge') ?>" style="display:inline" onsubmit="return confirm('Purge this exported file? The file and thumbnail will be kept for the gallery and the export record removed.');">
                            <?= csrf_field() ?><button class="btn small" type="submit" style="background:#7c3aed;color:#fff" title="Purge export after gallery created">Purge</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Thumbnail</th>
                    <th>ID</th>
                    <th>Title</th>
                    <th>User</th>
                    <th>Source</th>
                    <th>Version</th>
                    <th>Exports</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--text-muted)">No video projects yet.</td></tr>
                <?php else: ?>
                <?php foreach ($projects as $row): ?>
                <tr>
                    <td><?php if (!empty($row['source_filename'])): ?><a class="ve-thumb-link" href="<?= e(file_url($row['source_filename'], 'thumb')) ?>" data-thumb-preview><img class="ve-source-thumb" src="<?= e(file_url($row['source_filename'], 'thumb')) ?>" alt="Preview" loading="lazy"></a><?php else: ?>-<?php endif; ?></td>
                    <td><?= (int) $row['id'] ?></td>
                    <td><?= htmlspecialchars((string) ($row['title'] ?? 'Untitled')) ?></td>
                    <td><?= htmlspecialchars((string) ($row['user_email'] ?? '-')) ?></td>
                    <td><?= htmlspecialchars((string) ($row['source_filename'] ?? '-')) ?></td>
                    <td><?= (int) ($row['version'] ?? 1) ?></td>
                    <td><?= (int) ($row['export_count'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string) ($row['updated_at'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($row['source_filename'])): ?>
                        <a class="btn small" href="<?= url('/admin/videos/' . (int) $row['source_photo_id'] . '/edit') ?>">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<dialog class="ve-preview-dialog" id="ve-thumb-dialog"><button class="ve-preview-close" type="button" aria-label="Close">&times;</button><img alt="Thumbnail preview"></dialog>
<script>
(function () {
    var dialog = document.getElementById('ve-thumb-dialog');
    var image = dialog && dialog.querySelector('img');
    if (!dialog || !image) return;
    document.querySelectorAll('[data-thumb-preview]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            image.src = link.href;
            dialog.showModal();
        });
    });
    dialog.querySelector('.ve-preview-close').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
})();
</script>
