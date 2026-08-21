<style>
.error-log-toolbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.error-log-message{max-width:760px;white-space:pre-wrap;word-break:break-word;font-family:monospace;font-size:12px}.error-log-source{font-weight:600;color:var(--text-heading)}
</style>
<div class="error-log-toolbar">
    <h1>Error Logs</h1>
    <a class="btn" href="<?= url('/admin/video-projects') ?>">Video Projects</a>
</div>
<div class="card">
    <p class="text-muted">Failed exports and readable Apache, PHP, MySQL, and application log entries.</p>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Source</th><th>Time</th><th>Error</th></tr></thead>
            <tbody>
                <?php if (empty($errors)): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--text-muted)">No errors found.</td></tr>
                <?php else: foreach ($errors as $error): ?>
                <tr>
                    <td class="error-log-source"><?= e((string) ($error['source'] ?? 'Unknown')) ?></td>
                    <td><?= e((string) ($error['time'] ?? '')) ?></td>
                    <td class="error-log-message"><?= e((string) ($error['message'] ?? '')) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (isset($paginator) && (int) $paginator['pages'] > 1): ?>
    <?php $sep = strpos(url('/admin/error-logs'), '?') !== false ? '&' : '?'; ?>
    <div class="pagination">
        <?php if ($paginator['page'] > 1): ?>
            <a href="<?= e(url('/admin/error-logs')) ?><?= $sep ?>page=<?= $paginator['page'] - 1 ?>">&laquo; Prev</a>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $paginator['pages']; $p++): ?>
            <?php if ($p === (int) $paginator['page']): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e(url('/admin/error-logs')) ?><?= $sep ?>page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($paginator['page'] < $paginator['pages']): ?>
            <a href="<?= e(url('/admin/error-logs')) ?><?= $sep ?>page=<?= $paginator['page'] + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
