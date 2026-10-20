$title = 'Error Logs';
<div class="card">
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
    <?php // The shared admin pager (app/Core/AdminTable.php). ?>
    <?= \App\Core\AdminTable::pager([
        'base'  => url('/admin/error-logs'),
        'page'  => (int) ($paginator['page'] ?? 1),
        'pages' => (int) ($paginator['pages'] ?? 1),
    ]) ?>
</div>
