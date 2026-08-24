<?php

/**
 * Escape a string for safe output in HTML. Always escape dynamic values
 * before echoing them to prevent XSS (cross-site scripting) injection.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Build an application URL prefixed with the configured base path so links
 * work regardless of where the site is installed (e.g. under /gallery).
 */
/**
 * Read a value from the project .env file, falling back to the process
 * environment and then the default. Cached across calls.
 */
function env_value(string $key, string $default = ''): string
{
    static $env = null;

    if ($env === null) {
        $file = dirname(__DIR__, 2) . '/.env';
        $env  = is_readable($file) ? (parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
    }

    $value = $env[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_path'), '/');

    return $base . '/' . ltrim($path, '/');
}

/**
 * Build the URL used to serve an uploaded file, optionally pointing at a
 * generated variant served by StorageController: '' = the original full-size
 * file, 'thumb' = the 400x300 crop, 'web' = the fast-loading web variant.
 */
function file_url(string $filename, string $size = ''): string
{
    $query = in_array($size, ['thumb', 'web'], true) ? '?size=' . $size : '';

    // Version the regenerated variants by file mtime so the browser never
    // shows a stale thumbnail/web image after it is edited or recaptured.
    if ($size === 'thumb' || $size === 'web') {
        $variant = ($size === 'thumb' ? 'thumb_' : 'web_') . $filename;
        $mtime   = @filemtime(config('app.uploads.dir') . '/' . $variant);

        if ($mtime !== false) {
            $query .= '&v=' . $mtime;
        }
    }

    return url('/files/' . rawurlencode($filename) . $query);
}

/**
 * Determine whether an uploaded filename is a video based on its extension.
 * Used to branch between image and video rendering in views.
 */
function is_video(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return in_array($extension, config('app.uploads.video_ext'), true);
}

/**
 * Render a hidden CSRF token input. Every POST form must include this so the
 * framework can verify the request came from the same browser session.
 */
function csrf_field(): string
{
    return \App\Core\Csrf::field();
}

/**
 * Turn arbitrary text into a URL-friendly slug (lowercase, dashes instead of
 * spaces/symbols). Used to build clean category URLs.
 */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);

    return trim((string) $text, '-');
}

/**
 * Read a value from the config files under /config. The key is dotted, e.g.
 * 'app.uploads.thumb_width' -> config/app.php -> uploads -> thumb_width.
 */
function config(string $key, $default = null)
{
    static $cache = [];

    [$file, $path] = array_pad(explode('.', $key, 2), 2, null);

    if (!isset($cache[$file])) {
        $cache[$file] = require __DIR__ . '/../../config/' . $file . '.php';
    }

    $config = $cache[$file];

    if ($path === null) {
        return $config;
    }

    foreach (explode('.', $path) as $segment) {
        if (!is_array($config) || !array_key_exists($segment, $config)) {
            return $default;
        }
        $config = $config[$segment];
    }

    return $config;
}

/**
 * Read the EXIF Orientation tag from a JPEG (0-1 => none). Cameras and phones
 * store which way the photo was held; GD ignores this, so we must read it
 * ourselves or thumbnails would come out rotated.
 */
function exif_orientation_of(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }

    $info = @exif_read_data($path, 'IFD0');

    return ($info !== false && isset($info['Orientation'])) ? (int) $info['Orientation'] : 1;
}

/**
 * Rotate/flip a GD image resource according to the EXIF orientation so the
 * pixels are physically in the correct orientation before further processing.
 * Returns the (possibly new) image resource; callers must use the return value.
 */
function apply_exif_orientation($image, int $orientation)
{
    if ($orientation <= 1) {
        return $image;
    }

    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }

    switch ($orientation) {
        case 2:
            imageflip($image, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $rotated = imagerotate($image, 180, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
        case 4:
            imageflip($image, IMG_FLIP_VERTICAL);
            break;
        case 5:
            $rotated = imagerotate($image, -90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 6:
            $rotated = imagerotate($image, -90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
        case 7:
            $rotated = imagerotate($image, 90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
                imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 8:
            $rotated = imagerotate($image, 90, 0);
            if ($rotated !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
            break;
    }

    return $image;
}

/**
 * Load an image file into a GD resource, returning [$resource, $type] or
 * [false, 0] on failure. Shared by create_thumbnail and create_web_image.
 */
function _load_image(string $src)
{
    $info = @getimagesize($src);

    if ($info === false) {
        return [false, 0];
    }

    $type = $info[2];

    // Huge photos (e.g. 60+ megapixel camera captures) are extremely slow and
    // memory-heavy to decode and resample with GD alone. When the source is
    // larger than a working cap, pre-scale it with ffmpeg (which uses fast,
    // optimized resampling) into a temporary file, then load that smaller file
    // with GD. This makes uploads of very large images complete quickly.
    $maxWorking = 4000;
    $prescaled = null;

    if (max($info[0], $info[1]) > $maxWorking
        && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
        $prescaled = tempnam(sys_get_temp_dir(), 'imgload');

        if ($prescaled !== false) {
            $scale = $maxWorking / max($info[0], $info[1]);
            $destW  = max(1, (int) round($info[0] * $scale));
            $destH  = max(1, (int) round($info[1] * $scale));

            $cmd = escapeshellarg($ffmpeg) . ' -y -hide_banner -loglevel error -i '
                . escapeshellarg($src)
                . ' -vf scale=' . (int) $destW . ':' . (int) $destH
                . ' -frames:v 1 ' . escapeshellarg($prescaled) . ' 2>/dev/null';

            exec($cmd, $out, $rc);

            if ($rc !== 0 || !is_file($prescaled)) {
                @unlink($prescaled);
                $prescaled = null;
            } else {
                $src = $prescaled;
            }
        } else {
            $prescaled = null;
        }
    }

    $image = false;

    if ($type === IMAGETYPE_JPEG) {
        $image = @imagecreatefromjpeg($src);
    } elseif ($type === IMAGETYPE_PNG) {
        $image = @imagecreatefrompng($src);
    } elseif ($type === IMAGETYPE_GIF) {
        $image = @imagecreatefromgif($src);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $image = @imagecreatefromwebp($src);
    }

    if ($prescaled !== null) {
        @unlink($prescaled);
    }

    if ($image === false) {
        return [false, 0];
    }

    return [$image, $type];
}

/**
 * Generate a width x height thumbnail of an image file.
 * Applies EXIF orientation first (so photos are upright) and center-crops to
 * the target aspect ratio before resizing so thumbnails are never stretched.
 */
function create_thumbnail(string $src, string $dest, int $width, int $height): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    [$source, $type] = _load_image($src);

    if ($source === false) {
        return false;
    }

    $orientation = $type === IMAGETYPE_JPEG ? exif_orientation_of($src) : 1;
    $source = apply_exif_orientation($source, $orientation);

    if ($source === false) {
        return false;
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

    // Pick the smaller scale so the crop region keeps the source aspect ratio,
    // then take the center slice that matches the target aspect ratio.
    $scale  = min($srcW / $width, $srcH / $height);
    $cropW  = (int) min($srcW, $width * $scale);
    $cropH  = (int) min($srcH, $height * $scale);
    $srcX   = (int) (($srcW - $cropW) / 2);
    $srcY   = (int) (($srcH - $cropH) / 2);

    $thumb = imagecreatetruecolor($width, $height);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }

    imagecopyresampled(
        $thumb, $source,
        0, 0, $srcX, $srcY,
        $width, $height, $cropW, $cropH
    );

    $saved = false;
    if ($type === IMAGETYPE_JPEG) {
        $saved = imagejpeg($thumb, $dest, 85);
    } elseif ($type === IMAGETYPE_PNG) {
        $saved = imagepng($thumb, $dest, 6);
    } elseif ($type === IMAGETYPE_GIF) {
        $saved = imagegif($thumb, $dest);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
        $saved = imagewebp($thumb, $dest, 85);
    }

    imagedestroy($source);
    imagedestroy($thumb);

    return $saved;
}

/**
 * Generate the fast-loading web variant of an image: scaled down to fit
 * within maxDim on its longest side (aspect ratio preserved, never cropped)
 * and saved in the source format. The original file is left untouched so the
 * full-size version is always available.
 */
function create_web_image(string $src, string $dest, int $maxDim): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    [$source, $type] = _load_image($src);

    if ($source === false) {
        return false;
    }

    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }

    $source = apply_exif_orientation($source, $type === IMAGETYPE_JPEG ? exif_orientation_of($src) : 1);

    if ($source === false) {
        return false;
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

    if ($srcW <= $maxDim && $srcH <= $maxDim) {
        $saved = save_image($source, $dest, $type, 82);
        imagedestroy($source);

        return $saved;
    }

    $scale  = min(1, $maxDim / max($srcW, $srcH));
    $dstW   = max(1, (int) round($srcW * $scale));
    $dstH   = max(1, (int) round($srcH * $scale));
    $web    = imagecreatetruecolor($dstW, $dstH);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($web, false);
        imagesavealpha($web, true);
    }

    imagecopyresampled($web, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    imagedestroy($source);

    $saved = save_image($web, $dest, $type, 82);

    imagedestroy($web);

    return $saved;
}

/**
 * Write a GD image to disk in the format matching the given image type,
 * using quality settings tuned for display rather than archival.
 */
function save_image($image, string $dest, int $type, int $quality = 85): bool
{
    $saved = false;
    if ($type === IMAGETYPE_JPEG) {
        $saved = imagejpeg($image, $dest, $quality);
    } elseif ($type === IMAGETYPE_PNG) {
        $saved = imagepng($image, $dest, 6);
    } elseif ($type === IMAGETYPE_GIF) {
        $saved = imagegif($image, $dest);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagewebp')) {
        $saved = imagewebp($image, $dest, $quality);
    }

    return $saved;
}

/**
 * Map an EXIF orientation value to the ffmpeg transpose chain that bakes the
 * rotation into the pixels. ffmpeg ignores EXIF rotation metadata, so photos
 * from phones would come out sideways unless corrected here.
 */
function _exif_transpose_chain(int $orientation): string
{
    switch ($orientation) {
        case 2:
            return 'hflip';
        case 3:
            return 'transpose=1,transpose=1';
        case 4:
            return 'vflip';
        case 5:
            return 'transpose=0';
        case 6:
            return 'transpose=1';
        case 7:
            return 'transpose=3';
        case 8:
            return 'transpose=2';
    }

    return '';
}

/**
 * Generate the web variant and thumbnail from one source image in a single
 * ffmpeg pass: one decode feeds both output sizes through a filter_complex
 * split. This replaces the old double GD pipeline (thumbnail + web image
 * each loaded and decoded the full source separately), which made large
 * photo uploads take 15-30+ seconds; the single pass cuts that to ~2-5s.
 *
 * JPEGs take the ffmpeg route (the dominant, slowest upload type). Palette
 * and alpha formats keep the exact GD path. If ffmpeg is missing or fails,
 * the GD fallback still produces both variants so uploads never break.
 */
function create_image_variants(string $src, string $webDest, string $thumbDest, int $maxDim, int $thumbWidth, int $thumbHeight): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $info = @getimagesize($src);

    if ($info === false) {
        return false;
    }

    [$srcW, $srcH, $type] = $info;

    if ($type === IMAGETYPE_JPEG && is_executable('/usr/bin/ffmpeg')) {
        $orientation = exif_orientation_of($src);

        // Orientations 5-8 swap width/height relative to the stored pixels.
        $swap = $orientation >= 5;
        $effW = $swap ? $srcH : $srcW;
        $effH = $swap ? $srcW : $srcH;

        // Sources already within the display cap are passed through untouched
        // (no generation loss); anything needing rotation must be re-encoded
        // so the upright pixels land in the file itself.
        if ($effW <= $maxDim && $effH <= $maxDim && $orientation === 1) {
            $webChain = '[a]copy[w]';
        } else {
            $scale = min(1.0, $maxDim / max($effW, $effH));
            $webW  = max(1, (int) round($effW * $scale));
            $webH  = max(1, (int) round($effH * $scale));
            $webChain = "[a]scale={$webW}:{$webH}[w]";
        }

        $pre       = _exif_transpose_chain($orientation);
        $filters   = ($pre !== '' ? $pre . ',' : '') . 'split=2[a][b]'
            . ';' . $webChain
            . ";[b]scale={$thumbWidth}:{$thumbHeight}:force_original_aspect_ratio=increase,crop={$thumbWidth}:{$thumbHeight}[t]";

        $cmd = escapeshellarg('/usr/bin/ffmpeg')
            . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($src)
            . ' -filter_complex "' . $filters . '"'
            . ' -map "[w]" -frames:v 1 -q:v 4 ' . escapeshellarg($webDest)
            . ' -map "[t]" -frames:v 1 -q:v 5 ' . escapeshellarg($thumbDest)
            . ' 2>/dev/null';

        exec($cmd, $out, $rc);

        if ($rc === 0 && is_file($webDest) && is_file($thumbDest)) {
            return true;
        }

        @unlink($webDest);
        @unlink($thumbDest);
    }

    return _variants_gd($src, $webDest, $thumbDest, $maxDim, $thumbWidth, $thumbHeight);
}

/**
 * Single-decode GD fallback for create_image_variants: loads the source once
 * (with the usual huge-image pre-scale), writes the web variant, then derives
 * the thumbnail from the web-sized copy instead of re-loading the original.
 */
function _variants_gd(string $src, string $webDest, string $thumbDest, int $maxDim, int $thumbWidth, int $thumbHeight): bool
{
    [$source, $type] = _load_image($src);

    if ($source === false) {
        return false;
    }

    $orientation = $type === IMAGETYPE_JPEG ? exif_orientation_of($src) : 1;
    $source = apply_exif_orientation($source, $orientation);

    if ($source === false) {
        return false;
    }

    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

    if ($srcW <= $maxDim && $srcH <= $maxDim) {
        $web = $source;
    } else {
        $scale = min(1.0, $maxDim / max($srcW, $srcH));
        $dstW  = max(1, (int) round($srcW * $scale));
        $dstH  = max(1, (int) round($srcH * $scale));

        $web = imagecreatetruecolor($dstW, $dstH);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($web, false);
            imagesavealpha($web, true);
        }

        imagecopyresampled($web, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);
    }

    save_image($web, $webDest, $type, 82);

    $wW = imagesx($web);
    $wH = imagesy($web);

    $scale = min($wW / $thumbWidth, $wH / $thumbHeight);
    $cropW = (int) min($wW, $thumbWidth * $scale);
    $cropH = (int) min($wH, $thumbHeight * $scale);
    $srcX  = (int) (($wW - $cropW) / 2);
    $srcY  = (int) (($wH - $cropH) / 2);

    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
    }

    imagecopyresampled($thumb, $web, 0, 0, $srcX, $srcY, $thumbWidth, $thumbHeight, $cropW, $cropH);
    $saved = save_image($thumb, $thumbDest, $type, 85);

    imagedestroy($thumb);
    imagedestroy($web);

    return $saved;
}

/**
 * Grab a still frame from a video with ffmpeg and scale/crop it to a
 * thumbnail. Tries the 1-second mark first and falls back to 0 seconds for
 * clips that do not have a frame at that offset.
 */
function create_video_thumbnail(string $src, string $dest, int $width, int $height): bool
{
    foreach ([1, 0] as $second) {
        if (create_video_frame($src, $dest, $width, $height, $second)) {
            return true;
        }
    }

    return false;
}

/**
 * Capture the frame at an exact time offset (in seconds) from a video and
 * scale/crop it to the given dimensions, saving as JPEG. Used by the admin
 * frame picker to choose any frame as the thumbnail.
 */
function create_video_frame(string $src, string $dest, int $width, int $height, int $second): bool
{
    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    $filter = sprintf(
        'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
        $width, $height, $width, $height
    );

    $second = max(0, $second);
    $time   = sprintf(
        '%02d:%02d:%02d',
        intdiv($second, 3600),
        intdiv($second % 3600, 60),
        $second % 60
    );

    $cmd = escapeshellarg($ffmpeg) . ' -y -ss ' . escapeshellarg($time)
        . ' -i ' . escapeshellarg($src)
        . ' -an -frames:v 1 -vf ' . escapeshellarg($filter)
        . ' -q:v 5 -f mjpeg ' . escapeshellarg($dest) . ' 2>&1';

    exec($cmd, $output, $status);

    return $status === 0 && is_file($dest);
}
