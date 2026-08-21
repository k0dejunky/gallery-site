<style>.subhead{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.subhead h1{margin:0}</style>
<div class="subhead">
    <h1>Video Projects</h1>
    <a class="btn" href="<?= url('/admin/videos') ?>">Video list</a>
</div>
<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
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
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No video projects yet.</td></tr>
                <?php else: ?>
                <?php foreach ($projects as $row): ?>
                <tr>
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
<?php
$exportStatusLabel = static function (string $status): string {
    $map = [
        'queued'    => 'Queued',
        'running'   => 'Running',
        'completed' => 'Completed',
        'failed'    => 'Failed',
    ];
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
<div class="subhead" style="margin-top:28px">
    <h2>Exported files</h2>
</div>
<div class="card">
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Project</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Output file</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exports)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted)">No exports yet.</td></tr>
                <?php else: ?>
                <?php foreach ($exports as $ex): ?>
                <tr>
                    <td><?= (int) $ex['id'] ?></td>
                    <td><?= htmlspecialchars((string) ($ex['project_title'] ?? '-')) ?> <span class="text-muted">#<?= (int) $ex['project_id'] ?></span></td>
                    <td><?= htmlspecialchars((string) ($ex['user_email'] ?? '-')) ?></td>
                    <td>
                        <span style="color:<?= $ex['status'] === 'completed' ? 'var(--success,#2e7d32)' : ($ex['status'] === 'failed' ? 'var(--danger,#c62828)' : 'inherit') ?>"><?= $exportStatusLabel((string) $ex['status']) ?></span>
                        <?php if ((int) ($ex['progress'] ?? 0) > 0 && in_array($ex['status'], ['queued', 'running'], true)): ?> (<?= (int) $ex['progress'] ?>%)<?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($ex['output_file'] ?? '-')) ?></td>
                    <td><?= $formatBytes(isset($ex['file_size']) ? (int) $ex['file_size'] : null) ?></td>
                    <td><?= htmlspecialchars((string) ($ex['created_at'] ?? '')) ?></td>
                    <td>
                        <?php if ($ex['status'] === 'completed' && !empty($ex['output_file']) && !empty($ex['file_exists'])): ?>
                        <a class="btn small" href="<?= url('/admin/video-exports/' . (int) $ex['id'] . '/download') ?>">Download</a>
                        <?php elseif ($ex['status'] === 'failed' && !empty($ex['error'])): ?>
                        <span class="text-muted" title="<?= htmlspecialchars((string) $ex['error']) ?>">Error</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
