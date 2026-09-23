<?php
// Renders Prev/Next + a windowed set of numbered pages for a paginated
// listing. The $baseUrl and $paginator ($page, $pages) variables are
// expected from the view data. Only a window around the current page is
// shown (with first/last + ellipses) so large result sets don't render a
// wall of links.
if (isset($paginator) && (int) $paginator['pages'] > 1):
    $current = (int) $paginator['page'];
    $totalPages = (int) $paginator['pages'];
    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';

    $pages = [];
    for ($p = 1; $p <= $totalPages; $p++) {
        if ($p === 1 || $p === $totalPages || abs($p - $current) <= 2) {
            $pages[] = $p;
        } elseif (end($pages) !== null && $p - end($pages) > 1) {
            $pages[] = '…';
            $pages[] = $p;
        } else {
            $pages[] = $p;
        }
    }
    $pages = array_values(array_unique($pages));
    ?>
    <div class="pagination">
        <?php if ($current > 1): ?>
            <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $current - 1 ?>" rel="prev">&laquo; Prev</a>
        <?php endif; ?>

        <?php foreach ($pages as $p): ?>
            <?php if ($p === '…'): ?>
                <span class="page-gap" aria-hidden="true">&hellip;</span>
            <?php elseif ($p === $current): ?>
                <span class="current" aria-current="page" aria-label="Page <?= $p ?>"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $p ?>" aria-label="Page <?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($current < $totalPages): ?>
            <a href="<?= e($baseUrl) ?><?= $sep ?>page=<?= $current + 1 ?>" rel="next">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>