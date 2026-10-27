<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Photo;
use App\Models\Purchase;

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
        // ONE query for everything the access decision needs. The old code
        // made up to nine per file (findByFilename, hasPublicGallery,
        // userCanView, accessLevel, unlocksPhoto, ...), and every thumbnail
        // in a gallery grid is a request.
        $user      = Auth::user();
        $userId    = $user === null ? 0 : (int) $user['id'];
        $access    = Photo::accessInfo($name, $userId);

        if ($size !== 'thumb' && $size !== 'blur') {
            if ($user === null) {
                // A logged-out visitor may stream the WEB variants of a
                // public gallery - safe galleries at any level, adult ones in
                // the free tier. Those bytes are public by definition, so no
                // media token is required (and none is minted for them: their
                // URLs go into page-cacheable markup). The ORIGINAL file
                // still needs an account.
                $publicRendition = in_array($size, ['web', 'web_lo', 'web_hi', 'poster'], true);

                if ($access === null || !$access['guest_view'] || !$publicRendition) {
                    $this->notFound();
                    return;
                }
            } else {
                // Require a valid HMAC token so raw-URL replay from DevTools
                // is rejected. The token is bound to the original filename
                // plus the caller's session, so it can't be used without the
                // matching session cookie and stays valid for the life of the
                // session (bounded by the app's session idle timeout).
                if (!media_token_valid($name, $token)) {
                    $this->notFound();
                    return;
                }

                if ($access === null) {
                    Auth::requireSubscription();
                } else {
                    if (!$access['can_view']) {
                        $this->notFound();
                        return;
                    }
                    if (!$access['has_public']) {
                        // A secret-only photo is authorized by its allow-list;
                        // membership level never grants access to it.
                    } elseif ($access['unlocked']) {
                        // Paid PPV unlock for the gallery holding this photo:
                        // membership level does not apply.
                    } else {
                        Auth::requireGalleryLevel(
                            (int) $access['access_level'],
                            'A membership is required to view that file.'
                        );
                    }
                }
            }
        }

        if (in_array($size, ['thumb', 'blur'], true)) {
            if ($access !== null && !$access['has_public'] && !$access['can_view']) {
                $this->notFound();
                return;
            }
        }

        // A photo stored without its web_/thumb_ rendition (legacy import,
        // failed generation, cleaned storage) would otherwise serve the FULL
        // original through ?size=web - the "full size instead of the web
        // image" bug - or 404 the grid tile. Build the renditions on the first
        // request that needs them. image_can_decode() keeps videos on the
        // existing fallback (their renditions are made at upload time).
        if (in_array($size, ['web', 'thumb', 'blur'], true)) {
            $uploadsDir = config('app.uploads.dir');
            $srcPath    = $uploadsDir . '/' . $name;
            $webPath    = $uploadsDir . '/web_' . $name;
            $thumbPath  = $uploadsDir . '/thumb_' . $name;

            if (is_file($srcPath) && (!is_file($webPath) || !is_file($thumbPath)) && image_can_decode($srcPath)) {
                // Per-file lock (kept out of the uploads root so it never
                // shows up as an orphan file): requests for the SAME photo
                // wait for one generation instead of racing, while different
                // photos generate in parallel. Re-check under the lock so a
                // waiter skips work the previous holder already did.
                $lockDir = dirname(__DIR__, 2) . '/storage/cache/variants';
                @mkdir($lockDir, 0775, true);
                $lock = @fopen($lockDir . '/' . md5($name) . '.lock', 'c');
                if ($lock !== false) {
                    @flock($lock, LOCK_EX);
                    if (!is_file($webPath) || !is_file($thumbPath)) {
                        $cfg = config('app.uploads');
                        create_image_variants(
                            $srcPath,
                            $webPath,
                            $thumbPath,
                            (int) $cfg['web_max_width'],
                            (int) $cfg['thumb_width'],
                            (int) $cfg['thumb_height']
                        );
                    }
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                }
            }
        }

        // Variants resolve through media_variant_prefix() so the prefix used
        // to mint the URL is always the prefix used to find the file. 'blur'
        // is the guest-facing blurred copy of the thumbnail, 'poster' the wide
        // player poster, and 'web_lo'/'web_hi' the extra video ladder rungs.
        if (in_array($size, ['thumb', 'web', 'blur', 'poster', 'web_lo', 'web_hi'], true)) {
            $name = media_variant_prefix($size) . $name;
        }

        // A missing web rendition (e.g. a video uploaded before renditions were
        // generated) falls back to the original file instead of 404ing.
        if ($size === 'web' && !is_file(config('app.uploads.dir') . '/' . $name)) {
            $name = preg_replace('/^web_/', '', $name);
        }

        // A ladder rung that was never built (or was cleaned up) degrades to
        // the 720p rendition rather than to the multi-hundred-MB original: the
        // player's quality menu only offers rungs that exist, so this only
        // matters if a rung disappears mid-session.
        if (($size === 'web_lo' || $size === 'web_hi') && !is_file(config('app.uploads.dir') . '/' . $name)) {
            $fallback = config('app.uploads.dir') . '/web_' . $file;
            if (is_file($fallback)) {
                $name = 'web_' . $name;
            } else {
                $name = $file;
            }
        }

        // Prefer the WebP copy of a variant when requested and present.
        if ($format === 'webp' && in_array($size, ['thumb', 'web'], true)) {
            $webpName = preg_replace('/\.[^.]+$/', '.webp', $name);
            $webpPath = config('app.uploads.dir') . '/' . $webpName;

            if (is_file($webpPath)) {
                $name = $webpName;
            }
        }

        // AVIF copy of the same variant (encoded at upload time).
        if ($format === 'avif' && in_array($size, ['thumb', 'web'], true)) {
            $avifName = preg_replace('/\.[^.]+$/', '.avif', $name);
            $avifPath = config('app.uploads.dir') . '/' . $avifName;

            if (is_file($avifPath)) {
                $name = $avifName;
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
            } elseif (is_file($thumbPath)) {
                // GD could not blur this image (unsupported format, memory
                // limits, ...): serve the unblurred thumbnail instead of 404ing.
                $path = $thumbPath;
            }
        }

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        $mime = in_array($size, ['thumb', 'blur', 'poster'], true)
            ? $this->imageMimeOf($path)
            : (sniff_mime($path) ?: mime_for_extension($name));

        // Defense in depth: only ever serve media content-types. If a stored
        // file sniffs as anything else (e.g. html/svg/text), force it to a
        // generic binary so it can never render as active content (with the
        // nosniff header this also kills XSS via upload).
        if (!preg_match('#^(image/|video/|audio/|application/pdf|text/plain)#', (string) $mime)) {
            $mime = 'application/octet-stream';
        }

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');

        // Variants (web_/thumb_/blur_/poster_/ladder rungs) are
        // content-addressed: the derived file is regenerated and given a new
        // name when the source changes, so the URL is permanently immutable and
        // can be cached forever in the browser. Originals keep a shorter
        // public cache (files could be rotated/replaced with the same name).
        $isVariant = in_array($size, ['thumb', 'web', 'blur', 'poster', 'web_lo', 'web_hi'], true);
        header('Cache-Control: ' . ($isVariant ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));

        // Offload file streaming to Apache via X-SendFile (mod_xsendfile).
        // Apache natively supports HTTP Range requests, so browsers can seek
        // in large video files without streaming the bytes through PHP.
        // XSendFilePath is configured in the Apache vhost.
        header('X-Sendfile: ' . $path);

        return;
    }

    /**
     * Serve a video's short public sample clip (for search-engine video
     * indexing). Intentionally small (first ~12s, ≤480p, blurred) and
     * intentionally public — no session, token or membership gate — so Google
     * can fetch it to verify and index the video. Only ever serves the
     * generated clip for a real video in a published, non-secret gallery;
     * unpublished/scheduled or secret media (and full-length files) are never
     * reachable here.
     */
    public function preview(int $id): void
    {
        if ($id <= 0) {
            $this->notFound();
            return;
        }

        $photo = Photo::find($id);

        if ($photo === null || (int) ($photo['is_video'] ?? 0) !== 1) {
            $this->notFound();
            return;
        }

        // Never leak clips for unpublished/scheduled or secret videos.
        if (!Photo::hasPublicGallery($id)) {
            $this->notFound();
            return;
        }

        $path = video_sample_path((string) $photo['filename']);

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=86400');
        // Apache (mod_xsendfile) streams the file and honours HTTP Range
        // requests, so seeking works without buffering bytes through PHP.
        header('X-Sendfile: ' . $path);
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

            // The blurred cover is what every guest (and every crawler) sees,
            // so it gets the modern formats too: without them the guest
            // grid was the one surface that could not use AVIF or WebP.
            _make_webp($dest);
            _make_avif($dest);

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
        $mime = sniff_mime($path);

        if ($mime !== '' && strpos($mime, 'image/') === 0) {
            return $mime;
        }

        $info = @getimagesize($path);

        if ($info !== false && isset($info['mime'])) {
            return $info['mime'];
        }

        return 'image/jpeg';
    }
}
