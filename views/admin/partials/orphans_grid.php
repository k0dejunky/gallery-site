<?php
// Orphaned-file grid + pagination. Rendered server-side by abandoned.php and
// loaded again via AJAX (AdminController@orphansPage) so paging keeps the
// browser's scroll position. Needs $orphans, $orphanPage, $orphanPages and
// $canOperate (the per-file delete is super admin only).
$viewUrl = static fn (string $name): string
    => url('/admin/abandoned-uploads/orphans/view?file=' . rawurlencode($name));
$pageUrl = static fn (int $p): string
    => url('/admin/abandoned-uploads' . ($p > 1 ? '?page=' . $p : ''));
$canOperate = $canOperate ?? false;
?>
<div id="orphan-results" data-url="<?= e(url('/admin/abandoned-uploads/orphans/page')) ?>">
    <div class="media-grid">
        <?php foreach ($orphans as $orphan): ?>
            <?php
            $name = (string) $orphan['name'];
            $vu   = $viewUrl($name);
            $sourceLabel = ['root' => 'Uploads', 'previews' => 'SEO clip', 'chat' => 'Chat'][$orphan['source'] ?? 'root'] ?? 'Uploads';
            ?>
            <div class="media-item">
                <a href="<?= $vu ?>" target="_blank" rel="noopener" style="position:relative;display:block;"
                   title="View <?= e($name) ?>">
                    <?php if ($orphan['type'] === 'image' || $orphan['type'] === 'video'): ?>
                        <img src="<?= $vu ?>&amp;thumb=1" alt="<?= e($name) ?>" loading="lazy">
                        <?php if ($orphan['type'] === 'video'): ?>
                            <span style="position:absolute;inset:0;display:grid;place-items:center;color:var(--adm-inverse);font-size:1.7rem;text-shadow:0 1px 5px rgba(0,0,0,.65);pointer-events:none;">&#9654;</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="aspect-ratio:4/3;display:grid;place-items:center;background:var(--purple-900);color:var(--pink-200);font-size:1.5rem;">&#128196;</div>
                    <?php endif; ?>
                </a>
                <span class="media-name" title="<?= e($name) ?>"><?= e(mb_strimwidth($name, 0, 34, '…')) ?></span>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.25rem;">
                    <span class="muted" style="font-size:.7rem;"><?= e($sourceLabel) ?> · <?= number_format($orphan['size'] / 1048576, 1) ?> MB</span>
                    <a class="btn btn-sm btn-outline" href="<?= $vu ?>" target="_blank" rel="noopener">View</a>
                </div>
                <?php if ($canOperate): ?>
                    <form class="inline" method="post" action="<?= url('/admin/abandoned-uploads/orphans/delete') ?>"
                          data-confirm="Delete <?= e($name) ?> permanently?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="name" value="<?= e($name) ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($orphanPages > 1): ?>
        <?php
            $from = max(1, (int) $orphanPage - 3);
            $to   = min((int) $orphanPages, (int) $orphanPage + 3);
        ?>
        <div class="pagination">
            <?php if ($orphanPage > 1): ?>
                <a href="<?= $pageUrl((int) $orphanPage - 1) ?>" data-page="<?= (int) $orphanPage - 1 ?>">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($p = $from; $p <= $to; $p++): ?>
                <?php if ($p === (int) $orphanPage): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= $pageUrl($p) ?>" data-page="<?= $p ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($orphanPage < $orphanPages): ?>
                <a href="<?= $pageUrl((int) $orphanPage + 1) ?>" data-page="<?= (int) $orphanPage + 1 ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
