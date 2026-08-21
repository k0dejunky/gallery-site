<?php $title = 'Manage Categories'; ?>

<?php if ($editCategory !== null): ?>
    <?php // Editing: the Add form is swapped for a pre-filled rename form. ?>
    <h2>Edit Category</h2>
    <form method="post" action="<?= url('/admin/categories/' . (int) $editCategory['id']) ?>">
        <?= csrf_field() ?>
        <p style="text-align: center;">
            <input type="text" name="name" value="<?= e($editCategory['name']) ?>" required>
            <button type="submit" class="btn">Save</button>
            <a class="btn btn-sm" href="<?= url('/admin/categories' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Cancel</a>
        </p>
    </form>
<?php else: ?>
    <h2>Add Category</h2>
    <form method="post" action="<?= url('/admin/categories') ?>">
        <?= csrf_field() ?>
        <p style="text-align: center;">
            <input type="text" name="name" placeholder="e.g. Weddings, Travel, Nature" required>
            <button type="submit" class="btn">Add Category</button>
        </p>
    </form>
<?php endif; ?>

<h2>Categories</h2>
<?php // Search narrows the list to categories whose name matches the query. ?>
<form method="get" action="<?= url('/admin/categories') ?>">
    <p style="text-align: center;">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search categories...">
        <button type="submit" class="btn">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-sm" href="<?= url('/admin/categories') ?>">Clear</a>
        <?php endif; ?>
    </p>
</form>

<?php if (empty($categories)): ?>
    <?php if ($q !== ''): ?>
        <p>No categories match &ldquo;<?= e($q) ?>&rdquo;.</p>
    <?php else: ?>
        <p>No categories yet. Categories describe the content of a gallery (its photos and videos).</p>
    <?php endif; ?>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Galleries</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><?= e($category['name']) ?></td>
                    <td><?= (int) $category['gallery_count'] ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline" href="<?= url('/admin/categories/' . (int) $category['id'] . '/edit' . ($q !== '' ? '?q=' . rawurlencode($q) : '')) ?>">Edit</a>
                        <form class="inline" method="post" action="<?= url('/admin/categories/' . (int) $category['id'] . '/delete') ?>"
                              onsubmit="return confirm('Delete category <?= e($category['name']) ?>?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
