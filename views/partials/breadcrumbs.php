<?php if (!empty($breadcrumbItems) && count($breadcrumbItems) > 1): ?>
<nav class="breadcrumbs" aria-label="Breadcrumb">
    <?php foreach ($breadcrumbItems as $i => $crumb): ?>
        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
        <?php if (!empty($crumb['url'])): ?>
            <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
        <?php else: ?>
            <span><?= e($crumb['label']) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
