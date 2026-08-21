<?php $title = 'Manage: ' . $gallery['title']; ?>

<?php // Per-photo controls: upload new files, edit caption/link, reorder, rotate images and remove from this gallery. ?>
<h1>Manage &ldquo;<?= e($gallery['title']) ?>&rdquo;</h1>

<?php // Gallery-level view tracking stats. ?>
<p class="muted">
    <?= number_format((int) ($gallery['views'] ?? 0)) ?> total views &middot; <?= number_format((int) ($gallery['unique_views'] ?? 0)) ?> unique viewers
</p>

<h2>Categories</h2>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="title" value="<?= e($gallery['title']) ?>">
    <input type="hidden" name="description" value="<?= e($gallery['description'] ?? '') ?>">
    <label class="type-switch" style="margin-bottom: var(--spacing-sm);">
        <span>Gallery type</span>
        <input type="hidden" name="type" value="images">
        <input type="checkbox" name="type" value="videos"<?= ($gallery['type'] ?? 'images') === 'videos' ? ' checked' : '' ?> aria-label="Toggle video gallery">
        <span class="type-switch-track" aria-hidden="true"></span>
        <strong class="type-switch-label"><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong>
    </label>
    <?php if (empty($categories)): ?>
        <p class="muted">No categories available.</p>
    <?php else: ?>
        <div class="chips">
            <?php foreach ($categories as $category): ?>
                <label class="chip favorite-option<?= in_array((int) $category['id'], $assigned ?? [], true) ? ' selected' : '' ?>" data-category-name="<?= e($category['name']) ?>">
                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>"<?= in_array((int) $category['id'], $assigned ?? [], true) ? ' checked' : '' ?>>
                    <?= e($category['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn">Save Gallery Settings</button>
</form>
<script>
(function () {
    var labels = document.querySelectorAll('[data-category-name]');
    function updateCategories() {
        labels.forEach(function (label) {
            var input = label.querySelector('input[type="checkbox"]');
            label.classList.toggle('selected', input.checked);
        });
    }
    labels.forEach(function (label) {
        label.querySelector('input[type="checkbox"]').addEventListener('change', updateCategories);
    });
    updateCategories();

    var typeSwitch = document.querySelector('.type-switch input[type="checkbox"]');
    var typeLabel = document.querySelector('.type-switch-label');
    if (typeSwitch && typeLabel) {
        typeSwitch.addEventListener('change', function () {
            typeLabel.textContent = typeSwitch.checked ? 'Video Gallery' : 'Image Gallery';
        });
    }
})();
</script>

<?php // Accept multiple images/videos at once; the controller enforces the gallery type per file. ?>
<h2>Upload photos</h2>
<p class="muted">
    This is an <strong><?= ($gallery['type'] ?? 'images') === 'videos' ? 'Video Gallery' : 'Image Gallery' ?></strong> —
    <?= ($gallery['type'] ?? 'images') === 'videos' ? 'only video files can be uploaded' : 'only image files can be uploaded' ?>.
</p>
<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <p>
        <input type="file" name="photos[]" accept="<?= ($gallery['type'] ?? 'images') === 'videos' ? 'video/*' : 'image/*' ?>" multiple>
        <button type="submit" class="btn">Upload</button>
    </p>
</form>

<h2>Photos</h2>
<?php if (empty($photos)): ?>
    <p>No photos yet.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Preview</th>
                <th>Caption / Link</th>
                <th>Views</th>
                <th>Order</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($photos as $photo): ?>
                <tr>
                    <td>
                        <?php if (is_video($photo['filename'])): ?>
                            <video src="<?= e(file_url($photo['filename'])) ?>" width="120" muted preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" width="80">
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/caption') ?>">
                            <?= csrf_field() ?>
                            <input type="text" name="caption" value="<?= e($photo['caption']) ?>" placeholder="Caption"><br>
                            <input type="url" name="link" value="<?= e($photo['link']) ?>" placeholder="https://link-to (optional)" size="30">
                            <button type="submit" class="btn btn-sm">Save</button>
                        </form>
                    </td>
                    <td>
                        <span title="Total views / unique views"><?= number_format((int) $photo['views']) ?> / <?= number_format((int) $photo['unique_views']) ?></span>
                    </td>
                    <td>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/move') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" class="btn btn-sm" title="Move up">&uarr;</button>
                        </form>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/move') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" class="btn btn-sm" title="Move down">&darr;</button>
                        </form>
                    </td>
                    <td>
                        <?php if (is_video($photo['filename'])): ?>
                            <a class="btn btn-sm" href="<?= url('/admin/videos/' . (int) $photo['id'] . '/edit') ?>">Edit Video</a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline" href="<?= url('/admin/photos/' . (int) $photo['id'] . '/edit?back=' . (int) $gallery['id']) ?>">Edit</a>
                        <?php endif; ?>
                        <?php if (!is_video($photo['filename'])): ?>
                            <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/rotate') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="left">
                                <button type="submit" class="btn btn-sm" title="Rotate left">&larr;</button>
                            </form>
                            <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/rotate') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="direction" value="right">
                                <button type="submit" class="btn btn-sm" title="Rotate right">&rarr;</button>
                            </form>
                        <?php endif; ?>
                        <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id'] . '/photos/' . (int) $photo['id'] . '/delete') ?>"
                              onsubmit="return confirm('Remove this photo from this gallery? The shared file will be kept if another gallery uses it.');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">Remove from gallery</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
