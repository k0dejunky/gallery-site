<?php $title = 'Manage Categories'; ?>

<?php // Add/rename and search share one toolbar card; the list is a card with a
      // single-line action group per row. ?>
<section class="adm-section">
    <div class="adm-section-head">
        <h2><?= $editCategory !== null ? 'Edit category' : 'Add a category' ?></h2>
        <p>Categories describe what is in a gallery and power the browse filters and the AI suggester.</p>
    </div>
    <div class="sys-card adm-toolbar">
        <?php if ($editCategory !== null): ?>
            <form method="post" action="<?= url('/admin/categories/' . (int) $editCategory['id']) ?>" class="adm-inline-form">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>Name</span>
                    <input type="text" name="name" value="<?= e($editCategory['name']) ?>" required>
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn">Save changes</button>
                    <a class="btn btn-sm btn-outline" href="<?= url('/admin/categories' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Cancel</a>
                </div>
            </form>
        <?php else: ?>
            <form method="post" action="<?= url('/admin/categories') ?>" class="adm-inline-form">
                <?= csrf_field() ?>
                <label class="adm-field">
                    <span>New category</span>
                    <input type="text" name="name" placeholder="e.g. Weddings, Travel, Nature" required>
                </label>
                <div class="adm-field-actions">
                    <button type="submit" class="btn">Add category</button>
                </div>
            </form>
        <?php endif; ?>

        <form method="get" action="<?= url('/admin/categories') ?>" class="adm-inline-form adm-inline-form-search">
            <label class="adm-field">
                <span>Search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by name&hellip;">
            </label>
            <div class="adm-field-actions">
                <button type="submit" class="btn btn-outline">Search</button>
                <?php if ($q !== ''): ?>
                    <a class="btn btn-sm btn-outline" href="<?= url('/admin/categories') ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<section class="adm-section">
    <div class="adm-section-head">
        <h2>Categories</h2>
        <p><?= count($categories) ? number_format(count($categories)) . ' shown' . ($q !== '' ? ' matching "' . $q . '"' : '') : 'None yet' ?></p>
    </div>
    <div class="sys-card">
    <?php if (empty($categories)): ?>
        <?php if ($q !== ''): ?>
            <?= admin_empty('search', 'No matches', 'No category name contains "' . $q . '".', ['href' => url('/admin/categories'), 'label' => 'Clear the search']) ?>
        <?php else: ?>
            <?= admin_empty('tag', 'No categories yet', 'Add one above and it becomes available on every gallery.') ?>
        <?php endif; ?>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="num">Galleries</th>
                    <th class="act">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><strong><?= e($category['name']) ?></strong></td>
                        <td class="num"><?= number_format((int) $category['gallery_count']) ?></td>
                        <td class="act">
                            <div class="adm-actions">
                                <a class="btn btn-sm" href="<?= url('/admin/categories/' . (int) $category['id'] . '/edit' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Edit</a>
                                <form class="inline" method="post" action="<?= url('/admin/categories/' . (int) $category['id'] . '/delete') ?>"
                                      data-confirm="Delete category <?= e($category['name']) ?>?">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    </div>
</section>
