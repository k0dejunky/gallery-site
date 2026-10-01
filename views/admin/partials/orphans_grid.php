<?php
// Orphaned-file grid + pagination. Rendered server-side by system.php and
// loaded again via AJAX (SystemController@orphansPage) so paging keeps the
// browser's scroll position. Needs $orphans, $orphanPage, $orphanPages.
$viewUrl = static fn (string $name): string => url('/admin/system/orphans/view/' . rawurlencode($name));
$pageUrl = static fn (int $p): string => url('/admin/system' . ($p > 1 ? '?page=' . $p : ''));
?>
<div id="orphan-results" data-url="<?= e(url('/admin/system/orphans/page')) ?>">
    <div class="media-grid">
        <?php foreach ($orphans as $orphan): ?>
            <?php $vu = $viewUrl((string) $orphan['name']); ?>
            <div class="media-item">
                <a href="<?= $vu ?>" target="_blank" rel="noopener" style="position:relative;display:block;"
                   title="View <?= e((string) $orphan['name']) ?>">
                    <?php if ($orphan['type'] === 'image' || $orphan['type'] === 'video'): ?>
                        <img src="<?= $vu ?>?thumb=1" alt="<?= e((string) $orphan['name']) ?>" loading="lazy">
                        <?php if ($orphan['type'] === 'video'): ?>
                            <span style="position:absolute;inset:0;display:grid;place-items:center;color:#fff;font-size:1.7rem;text-shadow:0 1px 5px rgba(0,0,0,.65);pointer-events:none;">&#9654;</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="aspect-ratio:4/3;display:grid;place-items:center;background:var(--purple-900);color:var(--pink-200);font-size:1.5rem;">&#128196;</div>
                    <?php endif; ?>
                </a>
                <span class="media-name" title="<?= e((string) $orphan['name']) ?>"><?= e(mb_strimwidth((string) $orphan['name'], 0, 34, '…')) ?></span>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.25rem;">
                    <span class="muted" style="font-size:.7rem;"><?= number_format($orphan['size'] / 1048576, 1) ?> MB</span>
                    <a class="btn btn-sm btn-outline" href="<?= $vu ?>" target="_blank" rel="noopener">View</a>
                </div>
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