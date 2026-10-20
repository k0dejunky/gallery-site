<?php $title = 'Gallery Management'; ?>


<div class="create-page">
    <div class="create-grid">
        <aside class="create-form-card">
            <h2>Gallery details</h2>
            <form method="post" action="<?= url('/admin/galleries') ?>" id="gallery-form">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="title">Title *</label>
                    <input type="text" name="title" id="title" placeholder="e.g. Beach Vacation" required>
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" rows="3" placeholder="Optional description…"></textarea>
                </div>

                <div class="field">
                    <label>Gallery type</label>
                    <div class="type-switch-row">
                        <label class="chip active">
                            <input type="radio" name="type" value="images" checked>
                            🖼 Images
                        </label>
                        <label class="chip">
                            <input type="radio" name="type" value="videos">
                            ▶ Videos
                        </label>
                    </div>
                    <span class="muted">Image galleries accept only images; video galleries only videos.</span>
                </div>

                <div class="field">
                    <label>Categories</label>
                    <?php if (empty($categories)): ?>
                        <span class="muted">No categories yet — <a href="<?= url('/admin/categories') ?>">add some first</a>.</span>
                    <?php else: ?>
                        <div class="cat-chips">
                            <?php foreach ($categories as $category): ?>
                                <label class="chip favorite-option">
                                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>">
                                    <?= e($category['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="min_level">Minimum membership level required</label>
                    <select name="min_level" id="min_level">
                        <option value="0" selected>No restriction (Level 0)</option>
                        <option value="1">Level 1 (Silver)</option>
                        <option value="2">Level 2 (Gold)</option>
                        <option value="3">Level 3 (Platinum)</option>
                    </select>
                </div>

                <div class="field">
                    <label><input type="checkbox" name="is_test" value="1" id="is-test"> Test gallery (do not announce it)</label>
                    <span class="muted">Members are not emailed about it, it does not show in their notifications, and it is not posted to social media. Use this for galleries you are still working on.</span>
                </div>

                <?php if (\App\Core\Auth::isSuperAdmin()): ?>
                <div class="field">
                    <label><input type="checkbox" name="is_secret" value="1" id="is-secret"> Secret gallery</label>
                    <span class="muted">Only selected users can see this gallery. Membership level does not grant access.</span>
                </div>
                <div class="field">
                    <label for="allowed-users">Allowed users</label>
                    <select class="u-w-full" name="allowed_users[]" id="allowed-users" multiple size="6">
                        <?php foreach (($accessUsers ?? []) as $accessUser): ?>
                            <option value="<?= (int) $accessUser['id'] ?>"><?= e($accessUser['email']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="muted">Hold Ctrl/Cmd to select multiple users.</span>
                </div>
                <?php endif; ?>

                <div class="field" id="publish-later-fields">
                    <label for="publish_at">Publish on this site at</label>
                    <input type="datetime-local" name="publish_at" id="publish_at" value="<?= e(\App\Models\Gallery::defaultPublishAt()) ?>">
                    <span class="muted">Used by "Add to gallery queue": the gallery stays hidden from the public site until this date and time, then appears automatically and its recommended X / Reddit posts are scheduled for the same moment. Leave as-is and use "Save Gallery" to publish immediately.</span>
                </div>

                <div class="create-actions">
                    <button type="submit" name="submit_action" value="save" class="btn" id="save-btn">Save Gallery</button>
                    <button type="submit" name="submit_action" value="queue" class="btn btn-outline" id="queue-btn">Add to gallery queue</button>
                    <a class="btn btn-outline" href="<?= url('/admin/galleries') ?>">Cancel</a>
                </div>
            </form>
        </aside>

        <section class="upload-panel">
            <?php
            $uploadHeading   = 'Files to add';
            $uploadCountNoun = 'uploaded';
            $uploadHint      = 'Image files for an image gallery';
            $uploadHintId    = 'dz-type-hint';
            require __DIR__ . '/partials/staged_uploads.php';
            ?>
        </section>
    </div>

    <?php // Collapsible gallery queue: galleries waiting for a future publish moment. ?>
    <details class="create-form-card u-mt-15" data-gallery-queue>
        <summary style="cursor:pointer;font-weight:600;">Gallery queue (<?= count($queuedGalleries ?? []) ?>)</summary>
        <?php if (empty($queuedGalleries)): ?>
            <p class="muted u-mt-075">No galleries are scheduled for a future publication.</p>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;margin-top:.75rem;">
                <thead>
                    <tr>
                        <th class="u-left-pad">Gallery</th>
                        <th class="u-left-pad">Scheduled</th>
                        <th style="text-align:right;padding:.4rem .5rem;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queuedGalleries as $queued): ?>
                        <tr>
                            <td class="u-pad-04-05">
                                <a href="<?= url('/admin/galleries/' . (int) $queued['id']) ?>"><?= e((string) $queued['title']) ?></a>
                            </td>
                            <td class="muted u-pad-04-05"><?= e(tzdate('Y-m-d H:i', (string) $queued['published_at'])) ?></td>
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
</div>

<?php
// The staged-upload client is shared with manage.php. The type comes from the
// gallery-type radios and the hint follows them; "Save Gallery" is the button
// disabled while a batch is running.
$uploadConfig = [
    'token'      => '#gallery-form',
    'typeInputs' => 'input[name="type"]',
    'type'       => 'images',
    'typeHint'   => 'dz-type-hint',
    'busyButton' => 'save-btn',
    'empty'      => 'No files uploaded yet.',
    'debug'      => true,
];
require __DIR__ . '/partials/upload_client.php';
?>
