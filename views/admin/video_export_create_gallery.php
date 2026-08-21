<?php $title = 'Create Video Gallery from Export'; ?>

<style>
    .gallery-create-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; text-align: left; max-width: 1200px; margin: 0 auto; }
    .gallery-create-grid input[type="text"], .gallery-create-grid textarea { width: 100%; box-sizing: border-box; }
    .gallery-create-actions { grid-column: 1 / -1; text-align: center; margin-top: 0.5rem; }
    @media (max-width: 600px) { .gallery-create-grid { grid-template-columns: 1fr; } }
    .export-preview-card { background: var(--card-bg, #f4f4f4); border: var(--input-border-width, 1px) solid var(--card-border, #ddd); border-radius: var(--border-radius, 6px); padding: 1rem; margin-bottom: 1.5rem; text-align: center; }
    .export-preview-card video { display: block; max-width: 100%; max-height: 300px; margin: 0 auto 0.75rem; border-radius: var(--border-radius, 6px); background: #000; }
    .export-preview-card .muted { font-size: var(--font-size-sm, 0.85rem); }
</style>

<form method="post" action="<?= url('/admin/video-exports/' . (int) $export['id'] . '/create-gallery') ?>">
    <?= csrf_field() ?>

    <div class="export-preview-card">
        <?php if (!empty($export['output_file'])): ?>
            <video controls preload="none" src="<?= url('/admin/video-exports/' . (int) $export['id'] . '/stream') ?>"></video>
        <?php endif; ?>
        <span class="muted">Export file: <?= e(basename((string) $export['output_file'])) ?></span>
    </div>

    <div class="gallery-create-grid">
        <div>
            <p>
                <label for="title">Title *</label><br>
                <input type="text" name="title" id="title" required value="<?= e($prefill['title']) ?>">
            </p>
            <p>
                <label for="description">Description</label><br>
                <textarea name="description" id="description" rows="3"><?= e($prefill['description']) ?></textarea>
            </p>
        </div>

        <div>
            <label>Categories</label><br>
            <?php if (empty($categories)): ?>
                <span class="muted">No categories yet.</span>
            <?php else: ?>
                <div class="chips chips-justify">
                    <?php foreach ($categories as $category): ?>
                        <label class="chip favorite-option">
                            <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>">
                            <?= e($category['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <label for="min_level">Minimum membership level required</label><br>
            <select name="min_level" id="min_level">
                <option value="0" <?= ($prefill['min_level'] ?? 0) == 0 ? 'selected' : '' ?>>No restriction (Level 0)</option>
                <option value="1" <?= ($prefill['min_level'] ?? 0) == 1 ? 'selected' : '' ?>>Level 1 (Silver)</option>
                <option value="2" <?= ($prefill['min_level'] ?? 0) == 2 ? 'selected' : '' ?>>Level 2 (Gold)</option>
                <option value="3" <?= ($prefill['min_level'] ?? 0) == 3 ? 'selected' : '' ?>>Level 3 (Platinum)</option>
                <option value="4" <?= ($prefill['min_level'] ?? 0) == 4 ? 'selected' : '' ?>>Level 4 (Diamond)</option>
            </select>
            <span class="muted">Members below this level cannot view this gallery.</span>
        </div>

        <div class="gallery-create-actions">
            <button type="submit" class="btn">Create Video Gallery</button>
        </div>
    </div>
</form>

<script>
    document.querySelectorAll('.favorite-option input[type="checkbox"]').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            checkbox.closest('.favorite-option').classList.toggle('selected', checkbox.checked);
        });
    });
</script>
