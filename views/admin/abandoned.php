<?php $title = 'Abandoned Uploads'; ?>

<p><a href="<?= url('/admin') ?>">&larr; Dashboard</a></p>
<h1>Abandoned Uploads</h1>
<p class="muted">Uploads staged during a session that ended before the gallery was created. Assign each one to a matching image or video gallery.</p>

<?php if (empty($uploads)): ?>
    <p>No abandoned uploads.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Preview</th>
                <th>File</th>
                <th>Type</th>
                <th>Size</th>
                <th>Assign To</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($uploads as $upload): ?>
                <?php $isVideo = (int) ($upload['is_video'] ?? 0) === 1; ?>
                <?php $session = rawurlencode($upload['session']); ?>
                <?php $file = rawurlencode($upload['filename']); ?>
                <tr>
                    <td>
                        <?php if ($isVideo): ?>
                            <video src="<?= e(url('/admin/abandoned-uploads/' . $session . '/' . $file)) ?>" width="160" muted controls preload="metadata"></video>
                        <?php else: ?>
                            <img src="<?= e(url('/admin/abandoned-uploads/' . $session . '/' . $file . '?size=thumb')) ?>" alt="" width="120">
                        <?php endif; ?>
                    </td>
                    <td><?= e($upload['filename']) ?></td>
                    <td><?= $isVideo ? 'Video' : 'Image' ?></td>
                    <td><?= number_format((int) $upload['size']) ?> B</td>
                    <td>
                        <form method="post" action="<?= url('/admin/abandoned-uploads/' . $session . '/' . $file) ?>">
                            <?= csrf_field() ?>
                            <select name="gallery_id" required>
                                <option value="">Choose a gallery</option>
                                <?php foreach ($galleries as $gallery): ?>
                                    <?php $galleryIsVideo = ($gallery['type'] ?? 'images') === 'videos'; ?>
                                    <?php if ($isVideo === $galleryIsVideo): ?>
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