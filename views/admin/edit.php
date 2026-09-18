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
        </select>
        <span class="muted">Members below this level cannot view this gallery.</span>
    </p>
    <?php if (\App\Core\Auth::isSuperAdmin()): ?>
    <?php $allowedIds = array_map('intval', array_column($allowedUsers ?? [], 'id')); ?>
    <p>
        <label><input type="checkbox" name="is_secret" value="1" <?= !empty($gallery['is_secret']) ? 'checked' : '' ?>> Secret gallery</label><br>
        <span class="muted">Only selected users can see this gallery. Membership level does not grant access.</span>
    </p>
    <p>
        <label for="allowed-users">Allowed users</label><br>
        <select name="allowed_users[]" id="allowed-users" multiple size="6" style="min-width:280px;">
            <?php foreach (($accessUsers ?? []) as $accessUser): ?>
                <option value="<?= (int) $accessUser['id'] ?>"<?= in_array((int) $accessUser['id'], $allowedIds, true) ? ' selected' : '' ?>><?= e($accessUser['email']) ?></option>
            <?php endforeach; ?>
        </select><br>
        <span class="muted">Hold Ctrl/Cmd to select multiple users.</span>
    </p>
    <?php endif; ?>
    <p>
        <label for="publish_at">Publish on this site at</label><br>
        <input type="datetime-local" name="publish_at" id="publish_at"
               value="<?= !empty($gallery['published_at']) && $gallery['published_at'] > gmdate('Y-m-d H:i:s')
                   ? e(\App\Models\Gallery::defaultPublishAt(strtotime((string) $gallery['published_at'])))
                   : '' ?>">
        <span class="muted">Leave blank to keep the current schedule; pick a future time to reschedule (the gallery's pending X / Reddit posts move to that moment).</span>
    </p>
    <p>
        <label class="chip <?= empty($gallery['published_at']) ? 'active' : '' ?>">
            <input type="radio" name="publish_action" value="keep" <?= empty($gallery['published_at']) ? 'checked' : '' ?>>
            Keep current schedule
        </label>
        <label class="chip <?= !empty($gallery['published_at']) ? 'active' : '' ?>">
            <input type="radio" name="publish_action" value="now" <?= !empty($gallery['published_at']) ? 'checked' : '' ?>>
            Publish now / clear schedule
        </label>
    </p>
    <p>
        <button type="submit" class="btn">Save Changes</button>
        <a class="btn btn-sm" href="<?= url('/admin/galleries/' . (int) $gallery['id']) ?>">Cancel</a>
    </p>
</form>

<?php // Collapsible gallery queue: galleries waiting for a future publish moment. ?>
<details class="create-form-card" style="margin-top:1.5rem;border:1px solid var(--card-border,#ddd);border-radius:var(--card-radius,8px);padding:1.25rem;background:var(--card-bg,#fff);" data-gallery-queue>
    <summary style="cursor:pointer;font-weight:600;">Gallery queue (<?= count($queuedGalleries ?? []) ?>)</summary>
    <?php if (empty($queuedGalleries)): ?>
        <p class="muted" style="margin-top:.75rem;">No galleries are scheduled for a future publication.</p>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;margin-top:.75rem;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:.4rem .5rem;">Gallery</th>
                    <th style="text-align:left;padding:.4rem .5rem;">Scheduled</th>
                    <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queuedGalleries as $queued): ?>
                    <tr>
                        <td style="padding:.4rem .5rem;">
                            <a href="<?= url('/admin/galleries/' . (int) $queued['id']) ?>"><?= e((string) $queued['title']) ?></a>
                        </td>
                        <td style="padding:.4rem .5rem;" class="muted"><?= e(tzdate('Y-m-d H:i', (string) $queued['published_at'])) ?></td>
                        <td style="padding:.4rem .5rem;text-align:right;">
                            <form class="inline" method="post" action="<?= url('/admin/galleries/' . (int) $queued['id'] . '/publish-now') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm">Publish now</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</details>

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
