<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Photo;

class StorageController extends Controller
{
    /**
     * Serve an uploaded file or generated variant. Thumbnails are public for
     * the login page; originals and web-sized variants require:
     *
     *  1. A valid GALLERY_MEDIA_KEY token in the query string so the URL
     *     can't be replayed from DevTools / new-tab / direct link without
     *     the signed HMAC.
     *  2. The viewer to reach the gallery's minimum membership level.
     *
     * Video playback is supported through HTTP Range requests (206 partial
     * content), which browsers require for seeking in large files.
     */
    public function serve(string $file): void
    {
        $name = basename($file);

        if ($name === '' || $name !== $file) {
            $this->notFound();
            return;
        }

        $size   = (string) $this->request->query('size', '');
        $format = (string) $this->request->query('format', '');
        $token  = (string) $this->request->query('t', '');

        // Thumbnails (and their blurred preview copies) remain available on
        // the guest login page, but originals and web-sized variants are
        // gated by a signed media token AND the viewer's gallery level. Files
        // with no photo record (e.g. video exports) keep the subscription
        // gate.
        if ($size !== 'thumb' && $size !== 'blur') {
            // Require a valid HMAC token so raw-URL replay from DevTools is
            // rejected. The token is bound to the original filename plus the
            // caller's session, so it can't be used without the matching
            // session cookie and stays valid for the life of the session
            // (bounded by the app's session idle timeout).
            if (!media_token_valid($name, $token)) {
                $this->notFound();
                return;
            }

            $photo = Photo::findByFilename($name);

            if ($photo === null) {
                Auth::requireSubscription();
            } else {
                Auth::requireGalleryLevel(
                    Photo::minimumGalleryLevel((int) $photo['id']),
                    'A membership is required to view that file.'
                );
            }
        }

        if ($size === 'thumb') {
            $name = 'thumb_' . $name;
        } elseif ($size === 'blur') {
            // Blurred preview copy: served on the guest login/signup pages
            // using the same heavy blur the auto-poster applies to previews.
            // Generated once from the existing thumbnail and cached.
            $name = 'blur_thumb_' . $name;
        } elseif ($size === 'web') {
            $name = 'web_' . $name;
        }

        // Prefer the WebP copy of a variant when requested and present.
        if ($format === 'webp' && in_array($size, ['thumb', 'web'], true)) {
            $webpName = preg_replace('/\.[^.]+$/', '.webp', $name);
            $webpPath = config('app.uploads.dir') . '/' . $webpName;

            if (is_file($webpPath)) {
                $name = $webpName;
            }
        }

        $uploadsDir = config('app.uploads.dir');
        $path       = $uploadsDir . '/' . $name;

        // Video editor exports are stored under uploads/exports/ but served
        // through the same /files/ endpoint (e.g. video galleries built from
        // an export). Fall back to that subdirectory when not in the root.
        if (!is_file($path)) {
            $path = $uploadsDir . '/exports/' . $name;
        }

        // Generate the blurred preview copy on first request from the cached
        // thumbnail (same blur the auto-poster uses). The blurred file is
        // written next to the thumbnail so later requests are served from
        // disk.
        if ($size === 'blur' && !is_file($path)) {
            $thumbPath = $uploadsDir . '/thumb_' . $file;
            $thumbDir  = $uploadsDir;

            if (!is_file($thumbPath)) {
                $thumbPath = $uploadsDir . '/exports/thumb_' . $file;
                $thumbDir  = $uploadsDir . '/exports';
            }

            // Cache the blurred copy next to the thumbnail it was derived
            // from (root or exports/) rather than the export fallback path.
            $dest = $thumbDir . '/blur_thumb_' . $file;

            $blurred = $this->generateBlurred($thumbPath, $dest);

            if ($blurred !== null) {
                $path = $blurred;
            }
        }

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        $mime = in_array($size, ['thumb', 'web', 'blur'], true) ? $this->imageMimeOf($path) : $this->mimeFor($name);
        $len  = (int) filesize($path);

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');

        // Offload file streaming to Apache via X-SendFile (mod_xsendfile).
        // Apache natively supports HTTP Range requests, so browsers can seek
        // in large video files without streaming the bytes through PHP.
        // XSendFilePath is configured in the Apache vhost.
        header('X-Sendfile: ' . $path);

        return;
    }

    /**
     * Generate a blurred preview copy from an existing thumbnail using the
     * same heavy blur the auto-poster applies to preview images. Returns the
     * destination path on success, or null when GD cannot process the image
     * (the caller then falls back to the unblurred thumbnail).
     */
    private function generateBlurred(string $src, string $dest): ?string
    {
        if (!is_file($src)) {
            return null;
        }

        $blurred = create_blurred_copy($src, \App\Models\AutoPostQueue::POST_IMAGE_BLUR_PERCENT);

        if ($blurred === null) {
            return null;
        }

        // create_blurred_copy writes to the system temp dir; move it into the
        // uploads dir so later requests serve the cached copy directly.
        $moved = @rename($blurred, $dest);

        if ($moved && is_file($dest)) {
            @chmod($dest, 0644);

            return $dest;
        }

        @unlink($blurred);

        return is_file($dest) ? $dest : null;
    }

    /**
     * Detect an image's real MIME type (for thumbnails, which are stored as
     * JPEG bytes even when the extension is a video's, e.g. mp4 thumbs).
     */
    private function imageMimeOf(string $path): string
    {
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            if ($mime !== false && strpos($mime, 'image/') === 0) {
                return $mime;
            }
        }

        $info = @getimagesize($path);

        if ($info !== false && isset($info['mime'])) {
            return $info['mime'];
        }

        return 'image/jpeg';
    }

    /**
     * Map a filename extension to its content type so files are streamed with
     * the correct header.
     */
    private function mimeFor(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            return 'image/jpeg';
        }
        if ($extension === 'png') {
            return 'image/png';
        }
        if ($extension === 'gif') {
            return 'image/gif';
        }
        if ($extension === 'webp') {
            return 'image/webp';
        }
        if ($extension === 'mp4' || $extension === 'm4v') {
            return 'video/mp4';
        }
        if ($extension === 'webm') {
            return 'video/webm';
        }
        if ($extension === 'ogg') {
            return 'video/ogg';
        }
        if ($extension === 'mov') {
            return 'video/quicktime';
        }
        if ($extension === 'avi') {
            return 'video/x-msvideo';
        }
        if ($extension === 'mkv') {
            return 'video/x-matroska';
        }

        return 'application/octet-stream';
    }
}
