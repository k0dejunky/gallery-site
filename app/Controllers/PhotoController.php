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

        $before = count(Gallery::photos($galleryId));
        $this->storeFiles($galleryId, $files);
        $after = count(Gallery::photos($galleryId));

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

        $this->flash('success', 'Photos uploaded.');
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

        $this->flash('success', count($selected) . ' image' . (count($selected) === 1 ? '' : 's')
            . ' queued for rotation. Processing continues in the background.');
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
                $ok      = ImageEditor::watermark(
                    $path,
                    (string) $this->request->post('text', ''),
                    $this->textSize(),
                    $this->number('opacity', 15)
                );
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

        $temp = tempnam(sys_get_temp_dir(), 'veframe');

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
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

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
    private function storeFiles(int $galleryId, array $files): void
    {
        $config      = config('app.uploads');
        $gallery     = Gallery::find($galleryId);
        $galleryType = ($gallery['type'] ?? 'images') === 'videos' ? 'videos' : 'images';
        $count       = count($files['name']);

        for ($i = 0; $i < $count; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            $error = $this->validate($files, $i, $config, $galleryType);

            if ($error !== null) {
                $this->flash('error', $files['name'][$i] . ': ' . $error);
                continue;
            }

            $hash     = sha1_file($files['tmp_name'][$i]);
            $existing = Photo::findByHash($hash);

            if ($existing !== null) {
                Gallery::attachPhoto($galleryId, (int) $existing['id']);
                continue;
            }

            $extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $filename  = uniqid('photo_', true) . '.' . $extension;
            $isImage   = in_array($extension, $config['image_ext'], true);

            $dest = $config['dir'] . '/' . $filename;

            if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
                $this->flash('error', $files['name'][$i] . ': could not be saved.');
                continue;
            }

            if ($isImage) {
                create_image_variants(
                    $dest,
                    $config['dir'] . '/web_' . $filename,
                    $config['dir'] . '/thumb_' . $filename,
                    $config['web_max_width'],
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            } elseif (is_video($filename)) {
                create_video_thumbnail(
                    $dest,
                    $config['dir'] . '/thumb_' . $filename,
                    $config['thumb_width'],
                    $config['thumb_height']
                );
            }

            $photoId = Photo::create($filename, $hash);
            Gallery::attachPhoto($galleryId, $photoId);
        }
    }

    /**
     * Validate a single uploaded file: size limit, allowed extension, gallery
     * type match and that the content really is an image or a video (not a
     * disguised payload). Returns an error message or null when the file is
     * acceptable.
     */
    private function validate(array $files, int $index, array $config, string $galleryType = 'images'): ?string
    {
        if ($files['size'][$index] > $config['max_size']) {
            return 'File is too large.';
        }

        $extension = strtolower(pathinfo($files['name'][$index], PATHINFO_EXTENSION));

        $allowed = array_merge($config['image_ext'], $config['video_ext']);

        if (!in_array($extension, $allowed, true)) {
            return 'File type not allowed.';
        }

        $isImage = in_array($extension, $config['image_ext'], true);

        if ($galleryType === 'videos' && $isImage) {
            return 'Video galleries can only contain video files.';
        }

        if ($galleryType === 'images' && !$isImage) {
            return 'Image galleries can only contain image files.';
        }

        if ($isImage) {
            if (!image_can_decode($files['tmp_name'][$index])) {
                return 'File is not a valid image.';
            }

            return null;
        }

        if (strpos($this->mimeOf($files['tmp_name'][$index]), 'video/') !== 0) {
            return 'File is not a valid video.';
        }

        return null;
    }

    /**
     * Detect a file's MIME type with finfo when available, falling back to an
     * empty string so video validation simply fails.
     */
    private function mimeOf(string $path): string
    {
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            return $mime !== false ? $mime : '';
        }

        return '';
    }
}
