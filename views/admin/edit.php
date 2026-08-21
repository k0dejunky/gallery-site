<?php $title = 'Edit Gallery'; ?>

<form method="post" action="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">
    <?= csrf_field() ?>
    <p>
        <label for="title">Title *</label><br>
        <input type="text" name="title" id="title" value="<?= e($gallery['title']) ?>" required>
    </p>
    <p>
        <label for="description">Description</label><br>
        <textarea name="description" id="description" rows="3" cols="40"><?= e($gallery['description']) ?></textarea>
    </p>
    <p>
        <label>Gallery type</label><br>
        <label class="chip <?= ($gallery['type'] ?? 'images') === 'images' ? 'active' : '' ?>">
            <input type="radio" name="type" value="images" <?= ($gallery['type'] ?? 'images') === 'images' ? 'checked' : '' ?>>
            Image Gallery
        </label>
        <label class="chip <?= ($gallery['type'] ?? 'images') === 'videos' ? 'active' : '' ?>">
            <input type="radio" name="type" value="videos" <?= ($gallery['type'] ?? 'images') === 'videos' ? 'checked' : '' ?>>
            Video Gallery
        </label>
        <span class="muted">Image galleries accept only image files; video galleries accept only video files.</span>
    </p>
    <p>
        <label>Categories</label><br>
        <?php if (empty($categories)): ?>
            <span class="muted">No categories yet — <a href="<?= url('/admin/categories') ?>">add some first</a>.</span>
        <?php else: ?>
            <?php foreach ($categories as $category): ?>
                <label class="chip">
                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>"
                        <?= in_array((int) $category['id'], $assigned, true) ? 'checked' : '' ?>>
                    <?= e($category['name']) ?>
                </label>
            <?php endforeach; ?>
        <?php endif; ?>
    </p>
    <p>
        <label for="min_level">Minimum membership level required</label><br>
        <select name="min_level" id="min_level">
            <option value="0" <?= ($gallery['min_level'] ?? 0) === 0 ? 'selected' : '' ?>>No restriction (Level 0)</option>
            <option value="1" <?= ($gallery['min_level'] ?? 0) === 1 ? 'selected' : '' ?>>Level 1 (Silver)</option>
            <option value="2" <?= ($gallery['min_level'] ?? 0) === 2 ? 'selected' : '' ?>>Level 2 (Gold)</option>
            <option value="3" <?= ($gallery['min_level'] ?? 0) === 3 ? 'selected' : '' ?>>Level 3 (Platinum)</option>
            <option value="4" <?= ($gallery['min_level'] ?? 0) === 4 ? 'selected' : '' ?>>Level 4 (Diamond)</option>
        </select>
        <span class="muted">Members below this level cannot view this gallery.</span>
    </p>
    <p>
        <button type="submit" class="btn">Save Changes</button>
        <a class="btn btn-sm" href="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">Cancel</a>
    </p>
</form>

<?php // Show every file already in this gallery so admins see the current contents. Videos show a short clip + poster; images show their thumbnail. ?>
<h2>Files in this gallery (<?= count($photos) ?>)</h2>
<?php if (empty($photos)): ?>
    <p class="muted">No files yet — <a href="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">upload files on the manage page</a>.</p>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($photos as $photo): ?>
            <div class="media-item">
                <?php if (is_video($photo['filename'])): ?>
                    <video src="<?= e(file_url($photo['filename']) . '#t=0,1') ?>" poster="<?= e(file_url($photo['filename'], 'thumb')) ?>" muted preload="metadata"></video>
                <?php else: ?>
                    <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <span class="media-name"><?= e($photo['filename']) ?></span>
                 <?php if (is_video($photo['filename'])): ?>
                     <a class="btn btn-sm" style="text-align:center" href="<?= url('/admin/videos/' . (int) $photo['id'] . '/edit') ?>">Open Video Editor</a>
                 <?php else: ?>
                     <a class="btn btn-sm btn-outline" style="text-align:center" href="<?= url('/admin/photos/' . (int) $photo['id'] . '/edit?back=' . (int) $gallery['id']) ?>">Edit</a>
                 <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="muted"><a href="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">Manage this gallery</a> to upload, rotate, or remove files.</p>
<?php endif; ?>
