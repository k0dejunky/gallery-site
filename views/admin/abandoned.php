<?php $title = 'Abandoned Uploads'; ?>

<?php if (empty($uploads)): ?>
    <p>No abandoned uploads.</p>
<?php else: ?>
    <?php $hasResumable = count(array_filter($uploads, static fn (array $u): bool => empty($u['incomplete']))) > 0; ?>
    <?php if ($hasResumable): ?>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:.75rem 0;">
        <strong>With selected:</strong>
        <button type="button" class="btn" id="resume-btn">Resume New Gallery</button>
        <span class="muted"><span id="selected-count">0</span> selected</span>
    </div>

    <form method="post" action="<?= url('/admin/abandoned-uploads/resume') ?>" id="resume-form">
        <?= csrf_field() ?>
    </form>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th><?= $hasResumable ? '<input type="checkbox" id="check-all" title="Select all">' : '' ?></th>
                <th>Preview</th>
                <th>File</th>
                <th>Type</th>
                <th>Size</th>
                <th>Assign To</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($uploads as $upload): ?>
                <?php if (!empty($upload['incomplete'])): ?>
                    <tr style="background:rgba(180,90,40,.06);">
                        <td></td>
                        <td><div style="width:120px;height:40px;display:flex;align-items:center;justify-content:center;background:rgba(180,90,40,.12);border-radius:6px;font-size:1.2rem;" title="Interrupted chunked upload">&#9888;</div></td>
                        <td><code><?= e((string) $upload['filename']) ?></code>
                            <span class="muted" style="font-size:.78rem;">(<?= (int) ($upload['chunks'] ?? 0) ?> chunks, never completed)</span></td>
                        <td><span class="pill pill-warn">Incomplete</span></td>
                        <td><?= number_format((int) $upload['size']) ?> B</td>
                        <td>
                            <form method="post" action="<?= url('/admin/abandoned-uploads/chunks-delete') ?>"
                                  onsubmit="return confirm('Delete these <?= (int) ($upload['chunks'] ?? 0) ?> incomplete upload chunks?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="session" value="<?= e((string) $upload['session']) ?>">
                                <input type="hidden" name="file" value="<?= e((string) $upload['filename']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete chunks</button>
                            </form>
                        </td>
                    </tr>
                <?php else: ?>
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
                <?php endif; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($hasResumable): ?>
    <script nonce="<?= csp_nonce() ?>">
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
<?php endif; ?>