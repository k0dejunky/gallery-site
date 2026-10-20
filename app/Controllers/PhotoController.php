<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\ImageEditor;
use App\Core\Request;
use App\Models\AuditLog;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\PhotoJob;

class PhotoController extends Controller
{
    /**
     * Every photo action is admin-only, so the guard runs once here instead
     * of inside each handler.
     */
    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requirePermission('galleries');

        if (preg_match('#/admin/galleries/(\d+)(?:/|$)#', $request->uri(), $match)) {
            $gallery = Gallery::find((int) $match[1]);
            if ($gallery !== null && !empty($gallery['is_secret']) && !Auth::isSuperAdmin()) {
                http_response_code(404);
                exit;
            }
        }

        if (preg_match('#/admin/photos/(\d+)(?:/|$)#', $request->uri(), $match)
            && Photo::hasSecretGallery((int) $match[1]) && !Auth::isSuperAdmin()) {
            http_response_code(404);
            exit;
        }
    }

    /**
     * Handle a multi-file upload POST into a gallery: validates, de-dupes by
     * content hash, saves files, generates thumbnails and links them.
     */
    public function upload(int $galleryId): void
    {
        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $files = $this->request->file('photos');

        if ($files === null) {
            $this->flash('error', 'No files selected.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $before = Gallery::photoCount($galleryId);
        $added  = $this->storeFiles($galleryId, $files);
        $after  = Gallery::photoCount($galleryId);

        if ($after > $before) {
            AuditLog::record(
                (int) Auth::user()['id'],
                'create',
                'photo',
                null,
                'Uploaded ' . ($after - $before) . ' photo(s) to gallery #' . $galleryId,
                ['photo_count' => $before],
                ['photo_count' => $after]
            );
        }

        $this->flash($added > 0 ? 'success' : 'error', $added > 0 ? $added . ' photo(s) uploaded.' : 'No photos were uploaded.');
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Save a photo's caption/link metadata.
     */
    public function updateCaption(int $galleryId, int $photoId): void
    {
        $photo = Photo::find($photoId);

        if ($photo === null) {
            $this->notFound();
            return;
        }

        $caption = $this->request->input('caption');
        $link    = trim((string) $this->request->post('link', ''));

        Photo::updateCaption($photoId, $caption, $link);

        if ($photo['caption'] !== $caption || (string) ($photo['link'] ?? '') !== $link) {
            AuditLog::record((int) Auth::user()['id'], 'update', 'photo', $photoId, 'Updated photo caption/link', ['caption' => $photo['caption'], 'link' => $photo['link']], ['caption' => $caption, 'link' => $link]);
        }

        $this->flash('success', 'Photo updated.');
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Remove a photo from a gallery; if no other gallery references it the
     * file, thumbnail and record are deleted too.
     */
    public function destroy(int $galleryId, int $photoId): void
    {
        if (Gallery::find($galleryId) === null) {
            $this->notFound();
            return;
        }

        $photo = Photo::find($photoId);
        $removed = false;

        if ($photo !== null) {
            $removed = Database::run(
                'DELETE FROM gallery_photo WHERE gallery_id = ? AND photo_id = ?',
                [$galleryId, $photoId]
            )->rowCount() > 0;

            if ($removed) {
                Photo::deleteIfOrphan($photoId);
                AuditLog::record((int) Auth::user()['id'], 'delete', 'photo', $photoId, 'Removed photo from gallery #' . $galleryId);
            }
        }

        $this->flash($removed ? 'success' : 'error', $removed
            ? 'Photo removed from this gallery.'
            : 'That photo is not attached to this gallery.');
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Move a photo up or down in a gallery's display order.
     */
    public function move(int $galleryId, int $photoId): void
    {
        $direction = $this->request->input('direction', 'up');

        Gallery::movePhoto($galleryId, $photoId, $direction);

        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Rotate an image in place (left, right or 180) and regenerate its web
     * variant and thumbnail. Video files are rejected since rotation only
     * makes sense for images.
     */
    public function rotate(int $galleryId, int $photoId): void
    {
        $photo = Photo::find($photoId);

        if ($photo === null) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'error' => 'Photo not found.'], 404);
            }
            $this->notFound();
            return;
        }

        if (is_video($photo['filename'])) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'error' => 'Only images can be rotated.'], 400);
            }
            $this->flash('error', 'Only images can be rotated.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $direction = in_array($this->request->input('direction', 'right'), ['left', 'right', 'flip'], true)
            ? (string) $this->request->input('direction')
            : 'right';

        $config = config('app.uploads');
        $path   = $config['dir'] . '/' . $photo['filename'];

        if (!is_file($path)) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'error' => 'Image file not found.'], 404);
            }
            $this->flash('error', 'Image file not found.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        if (!ImageEditor::rotate($path, $direction)) {
            if ($this->isAjax()) {
                $this->json(['ok' => false, 'error' => 'Could not rotate image.'], 500);
            }
            $this->flash('error', 'Could not rotate image.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $this->regenerateVariants($photo, $config);

        AuditLog::record((int) Auth::user()['id'], 'update', 'photo', $photoId, 'Rotated image', ['filename' => $photo['filename']]);

        if ($this->isAjax()) {
            $this->json(['ok' => true]);
        }

        $this->flash('success', 'Image rotated.');
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Rotate selected images in one gallery and regenerate each pair of
     * display variants. Videos and photos from other galleries are ignored.
     */
    public function bulkRotate(int $galleryId): void
    {
        $gallery = Gallery::find($galleryId);
        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $direction = in_array($this->request->input('direction', 'right'), ['left', 'right'], true)
            ? (string) $this->request->input('direction')
            : 'right';
        $selected = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('photo_ids', [])),
            static fn (int $id): bool => $id > 0
        )));

        if ($selected === []) {
            $this->flash('error', 'Select at least one image to rotate.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $userId = (int) Auth::user()['id'];
        $jobId  = PhotoJob::createBulkRotate($userId, $galleryId, $direction, $selected);

        AuditLog::record(
            $userId,
            'update',
            'gallery',
            $galleryId,
            'Queued bulk rotate (background job #' . $jobId . ')',
            ['direction' => $direction, 'count' => count($selected)]
        );

        $note = count($selected) . ' image' . (count($selected) === 1 ? '' : 's')
            . ' queued for rotation. Processing continues in the background.';

        // The bulk form posts in the background (the page keeps the admin's
        // scroll position and the status updates in place), so answer with
        // the job instead of redirecting and reloading the whole page.
        if ($this->isAjax()) {
            $this->json(['ok' => true, 'job_id' => $jobId, 'message' => $note]);
        }

        $this->flash('success', $note);
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * The state of a background photo job, so the page can update the
     * "in progress" text without reloading.
     */
    public function job(int $id): void
    {
        Auth::requirePermission('galleries');

        $job = PhotoJob::findById($id);

        if ($job === null) {
            $this->json(['ok' => false, 'error' => 'No such job.'], 404);
            return;
        }

        $meta = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];

        $this->json([
            'ok'        => true,
            'id'        => (int) $job['id'],
            'status'    => (string) $job['status'],
            'operation' => (string) $job['operation'],
            'done'      => (int) $job['done'],
            'failed'    => (int) $job['failed'],
            'total'     => (int) $job['total'],
            'progress'  => (int) $job['progress'],
            'error'     => (string) ($job['error'] ?? ''),
            'active'    => in_array((string) $job['status'], ['queued', 'running'], true),
            // A finished watermark job can be undone, so the page offers the
            // button without a reload.
            'backups'   => (int) count((array) ($meta['backups'] ?? [])),
        ]);
    }

    /**
     * Set a caption on the selected photos in one gallery (background job).
     */
    public function bulkCaption(int $galleryId): void
    {
        if (Gallery::find($galleryId) === null) {
            $this->notFound();
            return;
        }

        $caption  = trim((string) $this->request->input('caption', ''));
        $selected = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('photo_ids', [])),
            static fn (int $id): bool => $id > 0
        )));

        if ($selected === []) {
            $this->flash('error', 'Select at least one photo to caption.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $userId = (int) Auth::user()['id'];
        $jobId  = PhotoJob::createBulkCaption($userId, $galleryId, $caption, $selected);

        AuditLog::record($userId, 'update', 'gallery', $galleryId, 'Queued bulk caption (background job #' . $jobId . ')', ['count' => count($selected)]);
        $note = count($selected) . ' photo' . (count($selected) === 1 ? '' : 's') . ' queued for captioning.';

        if ($this->isAjax()) {
            $this->json(['ok' => true, 'job_id' => $jobId, 'message' => $note]);
        }

        $this->flash('success', $note);
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Remove the selected photos from a gallery (background job; files are
     * deleted only when a photo is no longer in any gallery).
     */
    public function bulkDelete(int $galleryId): void
    {
        if (Gallery::find($galleryId) === null) {
            $this->notFound();
            return;
        }

        $selected = array_values(array_unique(array_filter(
            array_map('intval', (array) $this->request->post('photo_ids', [])),
            static fn (int $id): bool => $id > 0
        )));

        if ($selected === []) {
            $this->flash('error', 'Select at least one photo to remove.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $userId = (int) Auth::user()['id'];
        $jobId  = PhotoJob::createBulkDelete($userId, $galleryId, $selected);

        AuditLog::record($userId, 'update', 'gallery', $galleryId, 'Queued bulk delete (background job #' . $jobId . ')', ['count' => count($selected)]);
        $note = count($selected) . ' photo' . (count($selected) === 1 ? '' : 's') . ' queued for removal.';

        if ($this->isAjax()) {
            $this->json(['ok' => true, 'job_id' => $jobId, 'message' => $note]);
        }

        $this->flash('success', $note);
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Stream a JPEG sample of what the watermark looks like on one piece of
     * media in this gallery, so it can be approved before the whole gallery is
     * processed. The original is never touched: images are watermarked into a
     * temp file, videos are sampled at the 1s mark into a frame that is then
     * watermarked with the same GD path. ?photo= picks the sample media;
     * without it the gallery's first media is used.
     */
    public function watermarkSample(int $galleryId): void
    {
        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $logoPath = watermark_logo_path();

        if ($logoPath === null) {
            http_response_code(422);
            header('Content-Type: text/plain');
            echo 'The site title image could not be read. Upload one under Admin > Theme first.';
            return;
        }

        $config = config('app.uploads');

        $photoId = (int) $this->request->query('photo', '0');
        $photo   = $photoId > 0 ? Photo::find($photoId) : null;

        if ($photo !== null && Photo::firstGalleryId((int) $photo['id']) !== $galleryId) {
            $photo = null;
        }

        if ($photo === null) {
            $photos = Gallery::photos($galleryId);
            $photo  = $photos[0] ?? null;
        }

        if ($photo === null) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'This gallery has no media to sample.';
            return;
        }

        $path = $config['dir'] . '/' . $photo['filename'];

        if (!is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Media file not found.';
            return;
        }

        $tmp = gallery_temp_dir() . '/wm_sample_' . bin2hex(random_bytes(8)) . '.jpg';
        $ok  = false;

        try {
            if (is_video($photo['filename'])) {
                // Grab a frame at the source aspect ratio, then watermark the
                // frame - the same geometry/opacity the burn uses.
                $probe = [];
                $rc    = 0;
                @exec(
                    escapeshellarg('/usr/bin/ffprobe') . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 '
                    . escapeshellarg($path) . ' 2>/dev/null',
                    $probe,
                    $rc
                );

                $sw = 800;
                $sh = 450;
                if ($rc === 0 && isset($probe[0]) && strpos($probe[0], ',') !== false) {
                    [$vw, $vh] = array_map('intval', explode(',', (string) $probe[0]));
                    if ($vw > 0 && $vh > 0) {
                        $ratio = $vh / $vw;
                        $sw    = 800;
                        $sh    = max(2, (int) round(800 * $ratio / 2) * 2);
                    }
                }

                $ok = create_video_frame($path, $tmp, $sw, $sh, 1);

                if ($ok) {
                    $ok = ImageEditor::watermarkLogo($tmp, $logoPath, $tmp);
                }
            } else {
                $ok = ImageEditor::watermarkLogo($path, $logoPath, $tmp);
            }
        } catch (\Throwable $error) {
            error_log('[photo-edit] watermark sample failed: ' . $error->getMessage());
            $ok = false;
        }

        if (!$ok || !is_file($tmp)) {
            @unlink($tmp);
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Could not render the watermark sample.';
            return;
        }

        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=30');
        readfile($tmp);
        @unlink($tmp);
    }

    /**
     * Queue a watermark job for one gallery: the selected media, or every item
     * in the gallery when 'scope' is 'all'. Processing happens in the
     * supervised background worker (videos have to be re-encoded, which is far
     * too slow for an HTTP request).
     */
    public function bulkWatermark(int $galleryId): void
    {
        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        if (watermark_logo_path() === null) {
            $this->flash('error', 'The site title image is missing. Upload one under Admin > Theme before watermarking.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        // Accept both the bulk toolbar's repeated photo_ids[] inputs and the
        // watermark dialog's comma-joined hidden field.
        $rawIds = $this->request->post('photo_ids', []);

        if (is_string($rawIds)) {
            $rawIds = explode(',', $rawIds);
        }

        $selected = array_values(array_filter(array_unique(array_map(
            'intval',
            is_array($rawIds) ? $rawIds : []
        )), static fn (int $id): bool => $id > 0));

        if ($selected === [] && (string) $this->request->post('scope', '') === 'all') {
            $selected = array_map(
                static fn (array $photo): int => (int) $photo['id'],
                Gallery::photos($galleryId)
            );
        }

        if ($selected === []) {
            $this->flash('error', 'Select at least one photo or video to watermark.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $userId = (int) Auth::user()['id'];
        $jobId  = PhotoJob::createBulkWatermark($userId, $galleryId, $selected);

        AuditLog::record(
            $userId,
            'update',
            'gallery',
            $galleryId,
            'Queued watermark (background job #' . $jobId . ')',
            ['count' => count($selected)]
        );

        $note = count($selected) . ' item' . (count($selected) === 1 ? '' : 's')
            . ' queued for watermarking. Originals are backed up and can be restored. Processing continues in the background.';

        if ($this->isAjax()) {
            $this->json(['ok' => true, 'job_id' => $jobId, 'message' => $note]);
        }

        $this->flash('success', $note);
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Restore the originals taken by the last completed watermark job for this
     * gallery. Both images and videos are restored, each media's variants are
     * rebuilt from the restored file, and the job's backup list is cleared so a
     * second click cannot restore stale copies twice.
     */
    public function watermarkUndo(int $galleryId): void
    {
        $gallery = Gallery::find($galleryId);

        if ($gallery === null) {
            $this->notFound();
            return;
        }

        $job = Database::run(
            "SELECT * FROM photo_edit_jobs WHERE gallery_id = ? AND operation = ? AND status = 'completed' AND metadata_json LIKE '%\"backups\":[{%' ORDER BY id DESC LIMIT 1",
            [$galleryId, 'bulk_watermark']
        )->fetch();

        if (!$job) {
            $this->flash('error', 'There is no completed watermark run to undo for this gallery.');
            $this->redirect('/admin/galleries/' . $galleryId);
        }

        $meta    = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];
        $backups = is_array($meta['backups'] ?? null) ? $meta['backups'] : [];
        $config  = config('app.uploads');
        $restored = 0;

        foreach ($backups as $entry) {
            if (!is_array($entry) || !is_file((string) ($entry['path'] ?? ''))) {
                continue;
            }

            $photo = Photo::find((int) ($entry['photo_id'] ?? 0));

            if ($photo === null) {
                continue;
            }

            $path = $config['dir'] . '/' . $photo['filename'];

            // The backup IS the original: put it back over the watermarked file,
            // then consume the backup so a repeat click can't restore again and
            // the backups directory cannot grow without bound.
            if (@copy((string) $entry['path'], $path)) {
                @unlink((string) $entry['path']);
                $restored++;

                if (is_video($photo['filename'])) {
                    create_video_thumbnail(
                        $path,
                        $config['dir'] . '/thumb_' . $photo['filename'],
                        $config['thumb_width'],
                        $config['thumb_height']
                    );
                } else {
                    create_image_variants(
                        $path,
                        $config['dir'] . '/web_' . $photo['filename'],
                        $config['dir'] . '/thumb_' . $photo['filename'],
                        $config['web_max_width'],
                        $config['thumb_width'],
                        $config['thumb_height']
                    );
                }
            }
        }

        // Consume the backups so the button cannot run twice against one job.
        $meta['backups'] = [];
        Database::run('UPDATE photo_edit_jobs SET metadata_json = ? WHERE id = ?', [
            json_encode($meta),
            (int) $job['id'],
        ]);

        if ($restored > 0) {
            \App\Core\Cache::bump('gallery');
            \App\Core\Cache::bump('media');
        }

        AuditLog::record((int) Auth::user()['id'], 'update', 'gallery', $galleryId, 'Undid watermark (job #' . (int) $job['id'] . ')', ['restored' => $restored]);

        $this->flash(
            $restored > 0 ? 'success' : 'error',
            $restored > 0
                ? $restored . ' item' . ($restored === 1 ? '' : 's') . ' restored to the pre-watermark original.'
                : 'No backed-up originals could be restored for that run.'
        );
        $this->redirect('/admin/galleries/' . $galleryId);
    }

    /**
     * Admin photo editor: show the current media and every available edit
     * tool. Images get the full toolset (blur, sharpen, resize, rotate, crop,
     * text, watermark, thumbnail), videos get thumbnail selection (upload,
     * frame picker, regenerate) since GD cannot edit video pixels.
     */
    public function edit(int $id): void
    {
        $photo = Photo::find($id);

        if ($photo === null) {
            $this->notFound();
            return;
        }

        // Videos are edited in the video editor (NLE), not the photo editor.
        if (is_video($photo['filename'])) {
            $this->redirect('/admin/videos/' . $id . '/edit');
        }

        $back = (int) $this->request->query('back', (string) (Photo::firstGalleryId($id) ?? ''));

        $config     = config('app.uploads');
        $webExists  = is_file($config['dir'] . '/web_' . $photo['filename']);
        $thumbExists = is_file($config['dir'] . '/thumb_' . $photo['filename']);

        $this->viewAdmin('photo_edit', [
            'photo'      => $photo,
            'back'       => $back > 0 ? $back : null,
            'webExists'  => $webExists,
            'thumbExists' => $thumbExists,
        ]);
    }

    /**
     * Apply one edit operation POSTed from the photo editor, then regenerate
     * the derived variants and redirect back to where the admin came from.
     */
    public function applyEdit(int $id): void
    {
        $photo = Photo::find($id);

        if ($photo === null) {
            $this->notFound();
            return;
        }

        $operation = (string) $this->request->post('operation', '');

        if (is_video($photo['filename'])) {
            $this->applyVideoEdit($photo, $operation);

            return;
        }

        $this->applyImageEdit($photo, $operation);
    }

    /**
     * Dispatch an image edit operation. The stored file is normalised to its
     * EXIF orientation first so edits apply to upright pixels, then the web
     * variant and thumbnail are regenerated from the edited original.
     */
    private function applyImageEdit(array $photo, string $operation): void
    {
        $config = config('app.uploads');
        $path   = $config['dir'] . '/' . $photo['filename'];

        if (!is_file($path)) {
            $this->flash('error', 'Image file not found.');
            $this->backRedirect($photo);
        }

        if (!ImageEditor::normalize($path)) {
            $this->flash('error', 'Could not open image.');
            $this->backRedirect($photo);
        }

        $message = 'Image updated.';
        $ok      = true;
        $derive  = true;

        switch ($operation) {
            case 'blur':
                $ok      = ImageEditor::blur($path, $this->intensity('blur'));
                $message = 'Image blurred.';
                break;
            case 'sharpen':
                $ok      = ImageEditor::sharpen($path, $this->intensity('sharpen'));
                $message = 'Image sharpened.';
                break;
            case 'resize':
                $ok      = ImageEditor::resize($path, $this->maxDimension());
                $message = 'Image resized.';
                break;
            case 'rotate':
                $ok      = ImageEditor::rotate($path, $this->rotationDirection());
                $message = 'Image rotated.';
                break;
            case 'crop':
                $ok      = ImageEditor::cropRatio($path, $this->cropRatioValue());
                $message = 'Image cropped.';
                break;
            case 'text':
                $ok      = ImageEditor::addText(
                    $path,
                    (string) $this->request->post('text', ''),
                    $this->textSize(),
                    $this->textPosition(),
                    (string) $this->request->post('color', '#ffffff')
                );
                $message = 'Text added.';
                break;
            case 'watermark':
                // The watermark is the theme's title image, drawn once at 20%
                // opacity in the bottom-right corner.
                $ok      = ImageEditor::watermarkLogo($path);
                $message = 'Watermark applied.';
                break;
            case 'canvas':
                $ok      = $this->saveCanvas($path);
                $message = 'Realtime edit saved.';
                break;
            case 'thumbnail':
                $ok      = $this->uploadThumbnail($photo, $config);
                $derive  = false;
                $message = 'Thumbnail updated.';
                break;
            case 'regen':
                $ok      = create_thumbnail(
                    $path,
                    $config['dir'] . '/thumb_' . $photo['filename'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );
                $derive  = false;
                $message = 'Thumbnail regenerated.';
                break;
            default:
                $this->flash('error', 'Unknown operation.');
                $this->backRedirect($photo);
        }

        if (!$ok) {
            $this->flash('error', 'Could not apply edit.');
            $this->backRedirect($photo);
        }

        if ($derive) {
            $this->regenerateVariants($photo, $config);
        }

        AuditLog::record((int) Auth::user()['id'], 'update', 'photo', (int) $photo['id'], $message, ['filename' => $photo['filename']]);

        $this->flash('success', $message);
        $this->backRedirect($photo);
    }

    /**
     * Dispatch a video edit: only thumbnail operations make sense. Supports
     * uploading a custom thumbnail image, capturing a frame at a chosen
     * second and regenerating the automatic thumbnail.
     */
    private function applyVideoEdit(array $photo, string $operation): void
    {
        $config = config('app.uploads');
        $path   = $config['dir'] . '/' . $photo['filename'];
        $thumb  = $config['dir'] . '/thumb_' . $photo['filename'];

        if (!is_file($path)) {
            if ($this->isAjax()) { $this->json(['ok' => false, 'error' => 'Video file not found.'], 400); }
            $this->flash('error', 'Video file not found.');
            $this->backRedirect($photo);
        }

        $ok      = true;
        $message = 'Video updated.';

        switch ($operation) {
            case 'thumbnail':
                $ok      = $this->uploadThumbnail($photo, $config);
                $message = 'Thumbnail updated.';
                break;
            case 'frame':
                // The video editor sends a JPEG dataURL of the current frame
                // from the program monitor; fall back to an ffmpeg frame grab
                // at a chosen second for any other caller.
                $dataUrl = (string) $this->request->post('frame_data', '');
                if ($dataUrl !== '' && strpos($dataUrl, 'data:image/') === 0) {
                    $ok      = $this->saveFrameDataUrl($dataUrl, $thumb, $config);
                    $message = 'Thumbnail captured from the current frame.';
                } else {
                    $second  = max(0, (int) $this->request->post('second', 0));
                    $ok      = create_video_frame(
                        $path,
                        $thumb,
                        $config['thumb_width'],
                        $config['thumb_height'],
                        $second
                    );
                    $message = 'Thumbnail captured from the ' . $second . 's mark.';
                }
                break;
            case 'regen':
                $ok      = create_video_thumbnail(
                    $path,
                    $thumb,
                    $config['thumb_width'],
                    $config['thumb_height']
                );
                $message = 'Thumbnail regenerated.';
                break;
            default:
                if ($this->isAjax()) { $this->json(['ok' => false, 'error' => 'Unknown operation.'], 400); }
                $this->flash('error', 'Unknown operation.');
                $this->backRedirect($photo);
        }

        if (!$ok) {
            if ($this->isAjax()) { $this->json(['ok' => false, 'error' => 'Could not update thumbnail.'], 400); }
            $this->flash('error', 'Could not update thumbnail.');
            $this->backRedirect($photo);
        }

        AuditLog::record((int) Auth::user()['id'], 'update', 'photo', (int) $photo['id'], $message, ['filename' => $photo['filename']]);

        if ($this->isAjax()) {
            $this->json(['ok' => true, 'message' => $message]);
        }

        $this->flash('success', $message);
        $this->redirect('/admin/videos/' . (int) $photo['id'] . '/edit');
    }

    /**
     * Regenerate the fast-loading web variant and thumbnail from the original
     * (possibly edited) file. Videos only get a thumbnail, so callers for
     * videos should not invoke this.
     */
    private function regenerateVariants(array $photo, array $config): void
    {
        $path = $config['dir'] . '/' . $photo['filename'];

        create_image_variants(
            $path,
            $config['dir'] . '/web_' . $photo['filename'],
            $config['dir'] . '/thumb_' . $photo['filename'],
            $config['web_max_width'],
            $config['thumb_width'],
            $config['thumb_height']
        );
    }

    /**
     * Save an uploaded image as the photo's thumbnail (cropped/scaled to the
     * configured thumbnail dimensions). Rejects non-image files.
     */
    private function uploadThumbnail(array $photo, array $config): bool
    {
        $file = $this->request->file('thumbnail');

        if ($file === null) {
            return false;
        }

        $tmp   = is_array($file['tmp_name']) ? $file['tmp_name'][0] : $file['tmp_name'];
        $error = is_array($file['error']) ? $file['error'][0] : $file['error'];

        if ($error !== UPLOAD_ERR_OK || @getimagesize($tmp) === false) {
            return false;
        }

        return create_thumbnail(
            $tmp,
            $config['dir'] . '/thumb_' . $photo['filename'],
            $config['thumb_width'],
            $config['thumb_height']
        );
    }

    /**
     * Save a dataURL of the video editor's current monitor frame as the video's
     * thumbnail, cropped/scaled to the configured thumbnail dimensions.
     */
    private function saveFrameDataUrl(string $dataUrl, string $thumbPath, array $config): bool
    {
        if (!preg_match('#^data:image/(?:jpeg|png|webp);base64,(.+)$#', $dataUrl, $match)) {
            return false;
        }

        $binary = base64_decode($match[1], true);

        if ($binary === false || $binary === '' || strlen($binary) > 25 * 1024 * 1024) {
            return false;
        }

        $temp = tempnam(gallery_temp_dir(), 'veframe');

        if ($temp === false || file_put_contents($temp, $binary) === false) {
            if ($temp !== false) {
                unlink($temp);
            }

            return false;
        }

        $ok = @getimagesize($temp) !== false
            && create_thumbnail($temp, $thumbPath, $config['thumb_width'], $config['thumb_height']);

        unlink($temp);

        return $ok;
    }

    /**
     * Persist the image rendered by the browser canvas. The canvas is capped
     * client-side to keep the editor responsive; this is an explicit save of
     * the visible preview, not an automatic mutation while sliders move.
     */
    private function saveCanvas(string $path): bool
    {
        $data = (string) $this->request->post('canvas_data', '');

        if (!preg_match('#^data:image/(?:jpeg|png);base64,(.+)$#', $data, $match)) {
            return false;
        }

        $binary = base64_decode($match[1], true);

        if ($binary === false || strlen($binary) > 25 * 1024 * 1024) {
            return false;
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            return false;
        }

        $temp = $path . '.canvas.' . bin2hex(random_bytes(6));
        $ok   = imagejpeg($image, $temp, 92);
        imagedestroy($image);

        if (!$ok || !rename($temp, $path)) {
            if (is_file($temp)) {
                unlink($temp);
            }

            return false;
        }

        return true;
    }

    /**
     * Redirect back to the gallery the admin was managing (via the hidden
     * back field or the photo's first gallery), falling back to the dashboard.
     */
    private function backRedirect(array $photo): void
    {
        $back = (int) $this->request->post('back', '');

        if ($back <= 0) {
            $back = (int) (Photo::firstGalleryId((int) $photo['id']) ?? 0);
        }

        $this->redirect($back > 0 ? '/admin/galleries/' . $back : '/admin');
    }

    /**
     * Whether the current request came from an in-page fetch (the video
     * editor's thumbnail tools) rather than a normal form POST. These calls
     * get a JSON reply instead of a redirect.
     */
    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    }

    /**
     * Reply with a JSON payload and exit.
     */

    /**
     * Map a light/medium/heavy preset to Gaussian blur iterations.
     */
    private function intensity(string $name): int
    {
        $levels = ['light' => 1, 'medium' => 2, 'heavy' => 4];

        return $levels[(string) $this->request->post($name, 'medium')] ?? 2;
    }

    /**
     * Validate the resize target (longest side in px), defaulting to 1200.
     */
    private function maxDimension(): int
    {
        $value = (int) $this->request->post('max_dim', 0);

        return in_array($value, [640, 800, 1024, 1200, 1600, 1920, 2560], true) ? $value : 1200;
    }

    /**
     * Validate a crop ratio preset, defaulting to 4:3.
     */
    private function cropRatioValue(): float
    {
        $ratios = ['1:1' => 1.0, '4:3' => 4 / 3, '3:2' => 1.5, '16:9' => 16 / 9];

        return $ratios[(string) $this->request->post('ratio', '4:3')] ?? (4 / 3);
    }

    /**
     * Validate a rotation direction preset.
     */
    private function rotationDirection(): string
    {
        $value = (string) $this->request->post('direction', 'right');

        return in_array($value, ['left', 'right', 'flip'], true) ? $value : 'right';
    }

    /**
     * Validate a text placement preset.
     */
    private function textPosition(): string
    {
        $value = (string) $this->request->post('position', 'bottom-right');

        return in_array($value, ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'], true)
            ? $value
            : 'bottom-right';
    }

    /**
     * Clamp a positive number field (font size etc.) to a sane range.
     */
    private function textSize(): int
    {
        return $this->number('size', 32);
    }

    /**
     * Read and clamp a numeric POST field between 1 and 300.
     */
    private function number(string $name, int $default): int
    {
        $value = (int) $this->request->post($name, (string) $default);

        return max(6, min(300, $value));
    }

    /**
     * Save each successfully-validated uploaded file into the uploads dir,
     * generate the appropriate thumbnail, and attach it to the gallery.
     * Files with an identical content hash are linked instead of re-saved,
     * so the same photo is never stored twice. The gallery's type (image or
     * video) is enforced here so video galleries stay video-only and image
     * galleries stay image-only.
     */
    private function storeFiles(int $galleryId, array $files): int
    {
        $config      = config('app.uploads');
        $gallery     = Gallery::find($galleryId);
        $galleryType = ($gallery['type'] ?? 'images') === 'videos' ? 'videos' : 'images';
        $count       = count($files['name']);
        $added       = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $meta = \App\Core\MediaUploader::inspect($files, $i, $config, $galleryType);
            if ($meta === null) {
                $this->flash('error', $files['name'][$i] . ': ' . \App\Core\MediaUploader::error());
                continue;
            }

            $filename = uniqid('photo_', true) . '.' . $meta['extension'];
            $dest     = $config['dir'] . '/' . $filename;

            if (!\App\Core\MediaUploader::saveFinal($files['tmp_name'][$i], $dest, $meta['is_image'], $config)) {
                $this->flash('error', $files['name'][$i] . ': ' . \App\Core\MediaUploader::error());
                continue;
            }

            \App\Core\MediaUploader::commit($galleryId, $filename, $meta['hash']);
            $added++;
        }

        return $added;
    }
}
