<?php $title = 'Abandoned Uploads'; ?>

<h1>Abandoned Uploads</h1>
<p class="muted">Uploads staged during a session that ended before the gallery was created. Assign each one to an existing gallery, or select files and resume the new-gallery flow to finish creating a gallery.</p>

<?php if (empty($uploads)): ?>
    <p>No abandoned uploads.</p>
<?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.75rem 0;">
        <strong>With selected:</strong>
        <button type="button" class="btn" id="resume-btn">Resume New Gallery</button>
        <span class="muted"><span id="selected-count">0</span> selected</span>
    </div>

    <form method="post" action="<?= url('/admin/abandoned-uploads/resume') ?>" id="resume-form">
        <?= csrf_field() ?>
    </form>

    <table>
        <thead>
            <tr>
                <th><input type="checkbox" id="check-all" title="Select all"></th>
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
                <?php $key = e($upload['session'] . '|' . $upload['filename']); ?>
                <tr>
                    <td>
                        <input type="checkbox" class="abandoned-check" value="<?= $key ?>" data-session="<?= $session ?>" data-file="<?= $file ?>">
                    </td>
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
    <script>
    (function () {
        var all = document.getElementById('check-all');
        var checks = Array.prototype.slice.call(document.querySelectorAll('.abandoned-check'));
        var countEl = document.getElementById('selected-count');
        var resumeBtn = document.getElementById('resume-btn');
        var resumeForm = document.getElementById('resume-form');

        function updateCount() {
            var n = checks.filter(function (c) { return c.checked; }).length;
            if (countEl) countEl.textContent = n;
        }

        if (all) {
            all.addEventListener('change', function () {
                checks.forEach(function (c) { c.checked = all.checked; });
                updateCount();
            });
        }
        checks.forEach(function (c) { c.addEventListener('change', updateCount); });

        if (resumeBtn && resumeForm) {
            resumeBtn.addEventListener('click', function () {
                var chosen = checks.filter(function (c) { return c.checked; });
                if (!chosen.length) {
                    alert('Select at least one upload to resume.');
                    return;
                }
                if (!confirm('Stage ' + chosen.length + ' upload(s) for a new gallery? You can finish creating it from the next page.')) return;
                chosen.forEach(function (c) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'files[]';
                    input.value = c.value;
                    resumeForm.appendChild(input);
                });
                resumeForm.submit();
            });
        }
    })();
    </script>
<?php endif; ?>