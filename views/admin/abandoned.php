<?php $title = 'Abandoned Uploads'; ?>

<p><a href="<?= url('/admin') ?>">&larr; Dashboard</a></p>
<h1>Abandoned Uploads</h1>
<p class="muted">Uploads saved without a gallery assignment. Assign each one to a matching image or video gallery.</p>

<?php if (empty($photos)): ?>
    <p>No abandoned uploads.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Preview</th>
                <th>File</th>
                <th>Uploaded</th>
                <th>Assign To</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($photos as $photo): ?>
                <?php $photoIsVideo = (int) ($photo['is_video'] ?? (is_video($photo['filename']) ? 1 : 0)) === 1; ?>
                <tr>
                    <td>
                        <?php if ($photoIsVideo): ?>
                            <video src="<?= e(file_url($photo['filename'])) ?>" width="160" muted controls preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(file_url($photo['filename'], 'thumb')) ?>" alt="" width="120">
                        <?php endif; ?>
                    </td>
                    <td><?= e($photo['filename']) ?></td>
                    <td><?= e($photo['created_at']) ?></td>
                    <td>
                        <form method="post" action="<?= url('/admin/abandoned-uploads/' . (int) $photo['id']) ?>">
                            <?= csrf_field() ?>
                            <select name="gallery_id" required>
                                <option value="">Choose a gallery</option>
                                <?php foreach ($galleries as $gallery): ?>
                                    <?php $galleryIsVideo = ($gallery['type'] ?? 'images') === 'videos'; ?>
                                    <?php if ($photoIsVideo === $galleryIsVideo): ?>
                                        <option value="<?= (int) $gallery['id'] ?>"><?= e($gallery['title']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Assign</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
