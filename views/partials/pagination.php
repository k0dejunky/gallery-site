<?php
// Renders Prev/Next + numbered pages for a paginated listing. The $baseUrl
// and $paginator ($page, $pages) variables are expected from the view data.
if (isset($paginator) && (int) $paginator['pages'] > 1): ?>
    <?php $sep = strpos($baseUrl, '?') !== false ? '&' : '?'; ?>
    <div class="pagination">
        <?php if ($paginator['page'] > 1): ?>
            <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $paginator['page'] - 1 ?>">&laquo; Prev</a>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $paginator['pages']; $p++): ?>
            <?php if ($p === (int) $paginator['page']): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($paginator['page'] < $paginator['pages']): ?>
            <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $paginator['page'] + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
