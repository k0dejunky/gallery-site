<?php

namespace App\Core;

/**
 * Shared media-upload pipeline used by every upload path (direct gallery
 * upload, staged pending upload, resumable chunk completion). Previously the
 * direct and staged paths each implemented their own validate/variant/commit
 * logic and had already diverged (the staged path sniffed MIME and ffprobed
 * videos while the direct path trusted extensions). This single service makes
 * every path reject the same way, store the real MIME-derived extension and
 * generate the same variants.
 */
class MediaUploader
{
    private static ?string $lastError = null;

    /** The error from the last failed operation, or null. */
    public static function error(): ?string
    {
        return self::$lastError;
    }

    /**
     * Validate one uploaded file and describe it. MIME-content based (sniff +
     * ffprobe for videos) so a disguised/polyglot file is rejected on every
     * path. On success returns ['mime','is_image','is_video','extension','hash'];
     * on failure returns null and sets error().
     *
     * @return array{0?: string, mime?: string, is_image?: bool, is_video?: bool, extension?: string, hash?: string}|null
     */
    public static function inspect(array $files, int $index, array $config, string $galleryType): ?array
    {
        self::$lastError = null;

        if ($files['size'][$index] > $config['max_size']) {
            self::$lastError = 'File is too large.';
            return null;
        }

        $mime = sniff_mime($files['tmp_name'][$index]);
        if ($mime === '') {
            self::$lastError = 'Could not detect file type.';
            return null;
        }

        $isImage = strpos($mime, 'image/') === 0;
        $isVideo = strpos($mime, 'video/') === 0;

        if (!$isImage && !$isVideo) {
            self::$lastError = 'File type not allowed.';
            return null;
        }

        $extension = self::extensionForMime($mime);
        $imageExt  = (array) ($config['image_ext'] ?? []);
        $videoExt  = (array) ($config['video_ext'] ?? []);

        if ($isImage && !in_array($extension, $imageExt, true)) {
            self::$lastError = 'Image type not allowed.';
            return null;
        }
        if ($isVideo && !in_array($extension, $videoExt, true)) {
            self::$lastError = 'Video type not allowed.';
            return null;
        }

        if ($galleryType === 'videos' && $isImage) {
            self::$lastError = 'Video galleries can only contain video files.';
            return null;
        }
        if ($galleryType === 'images' && $isVideo) {
            self::$lastError = 'Image galleries can only contain image files.';
            return null;
        }

        if ($isImage && !image_can_decode($files['tmp_name'][$index])) {
            self::$lastError = 'File is not a valid image.';
            return null;
        }
        if ($isVideo && !video_has_stream($files['tmp_name'][$index])) {
            self::$lastError = 'File is not a valid video.';
            return null;
        }

        return [
            'mime'      => $mime,
            'is_image'  => $isImage,
            'is_video'  => $isVideo,
            'extension' => $extension,
            'hash'      => sha1_file($files['tmp_name'][$index]) ?: '',
        ];
    }

    /**
     * Map a detected MIME type to a canonical filename extension.
     */
    public static function extensionForMime(string $mime): string
    {
        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
            'image/heic' => 'heic', 'image/heif' => 'heic',
            'image/avif' => 'avif', 'image/tiff' => 'tiff', 'image/x-tiff' => 'tiff',
            'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogg',
            'video/quicktime' => 'mov', 'video/x-quicktime' => 'mov',
            'video/x-msvideo' => 'avi', 'video/avi' => 'avi',
            'video/x-matroska' => 'mkv', 'video/x-m4v' => 'm4v',
            'video/3gpp' => '3gp', 'video/3gpp2' => '3g2',
            'video/mpeg' => 'mpg', 'video/x-mpeg' => 'mpg',
            'video/x-ms-wmv' => 'wmv', 'video/x-ms-asf' => 'wmv', 'video/x-ms-wm' => 'wmv',
            'video/x-flv' => 'flv', 'video/mp2t' => 'ts', 'video/mp2p' => 'ts',
        ];

        return $map[$mime] ?? 'bin';
    }

    /**
     * Generate display variants for a file already on disk, written next to
     * it (web_/thumb_ for images, thumb_ for videos). Works for files in the
     * main uploads dir or a staging dir. Returns false when the preview could
     * not be produced.
     */
    public static function generateVariants(string $path, bool $isImage, array $config): bool
    {
        $dir  = dirname($path);
        $name = basename($path);

        if ($isImage) {
            return create_image_variants(
                $path,
                $dir . '/web_' . $name,
                $dir . '/thumb_' . $name,
                $config['web_max_width'],
                $config['thumb_width'],
                $config['thumb_height']
            );
        }

        return create_video_thumbnail(
            $path,
            $dir . '/thumb_' . $name,
            $config['thumb_width'],
            $config['thumb_height']
        );
    }

    /**
     * Move an uploaded temp file to its final path and generate variants.
     * Rolls back (deletes the file + variants) when a preview cannot be made,
     * so a corrupt file never leaves a broken grid tile. Returns false on
     * failure (error() explains why).
     */
    public static function saveFinal(string $tmp, string $destPath, bool $isImage, array $config): bool
    {
        if (!move_uploaded_file($tmp, $destPath)) {
            self::$lastError = 'could not be saved.';
            return false;
        }

        if (self::generateVariants($destPath, $isImage, $config)) {
            // Videos are remuxed (stream copy, no re-encode) so the moov atom
            // sits at the front — browsers otherwise wait for the whole file
            // before they can start playback or seek — and a web-optimized
            // rendition (≤720p, ~2-4 Mbps) is created so playback streams a
            // fraction of the original's bandwidth.
            if (!$isImage) {
                set_time_limit(0);
                faststart_video_if_needed($destPath);
                create_video_web_rendition($destPath, dirname($destPath) . '/web_' . basename($destPath));
                create_video_sample_clip($destPath, video_sample_path(basename($destPath)));
            }

            return true;
        }

        $dir = dirname($destPath);
        foreach ([$destPath, $dir . '/web_' . basename($destPath), $dir . '/thumb_' . basename($destPath)] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        self::$lastError = 'Could not generate a preview (file may be corrupt or unsupported).';
        return false;
    }

    /**
     * Run the expensive video post-processing (faststart remux + web rendition)
     * in a detached background process so a multi-GB video never holds an
     * upload request open (the gallery is committed first; the web_ rendition
     * appears when the job finishes). Returns immediately.
     */
    public static function finalizeVideoAsync(string $path): void
    {
        $bootstrap = realpath(__DIR__ . '/../bootstrap.php');
        $code = 'require $argv[1]; faststart_video_if_needed($argv[2]);'
            . ' create_video_web_rendition($argv[2], dirname($argv[2]) . "/web_" . basename($argv[2]));'
            . ' create_video_sample_clip($argv[2], video_sample_path(basename($argv[2])));';
        // setsid detaches the job into its own session so the php-fpm worker's
        // cleanup on request completion can't reap it (nohup alone was killed).
        $cmd = 'setsid ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)
            . ' ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($path)
            . ' >/dev/null 2>&1 &';
        @exec($cmd);
    }

    /**
     * Commit a saved file into a gallery: skip when a photo with the same
     * content hash already exists (attach the existing one), otherwise create
     * the photo row and attach it. Returns the photo id.
     */
    public static function commit(int $galleryId, string $filename, string $hash): int
    {
        $config = \config('app.uploads');

        $existing = \App\Models\Photo::findByHash($hash);

        if ($existing !== null) {
            \App\Models\Gallery::attachPhoto($galleryId, (int) $existing['id']);

            return (int) $existing['id'];
        }

        $photoId = \App\Models\Photo::create($filename, $hash);
        \App\Models\Gallery::attachPhoto($galleryId, $photoId);

        // Cache the video duration (playlist rows show it without probing).
        if (is_video($filename)) {
            \App\Models\Photo::setDuration($photoId, video_duration_seconds($config['dir'] . '/' . $filename));
        }

        // Newly committed media: queue the gallery for AI category analysis
        // (driven by bin/categorize_worker.php; a no-op when the driver is
        // off or a job is already queued/running).
        \App\Models\CategorySuggestion::enqueue($galleryId);

        return $photoId;
    }
}