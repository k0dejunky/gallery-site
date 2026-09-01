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
 * Produce a session-bound HMAC token proving a request URL was handed out by
 * this exact browser session, backed by GALLERY_MEDIA_KEY from the .env file.
 * Non-thumb media served via /files/ require this token so a raw URL (e.g.
 * opened directly, or copied from DevTools by an outsider) can't be replayed:
 * the token is useless unless the requester also holds the matching session
 * cookie.
 *
 * Tokens have no short time expiry and stay valid for the life of the session,
 * so long video watch/seek (which reuses the URL across Range requests) is
 * never interrupted — the app's existing session idle timeout still bounds
 * how long a session (and therefore its tokens) remains usable.
 */
function media_token(string $path): string
{
    $secret = env_value('GALLERY_MEDIA_KEY');

    if ($secret === '') {
        return '';
    }

    return hash_hmac('sha256', $path . ':' . session_id(), $secret);
}

/**
 * Validate a media token for a path. $given comes from the request t=
 * query parameter; $path is the canonical target. Returns true when the
 * signature matches the token produced for the current session.
 */
function media_token_valid(string $path, string $given): bool
{
    if ($given === '') {
        return false;
    }

    $secret = env_value('GALLERY_MEDIA_KEY');

    if ($secret === '') {
        // No secret configured: the gate is inert so the site keeps working,
        // but originals/web sizes still require the membership level check.
        return true;
    }

    return hash_equals(media_token($path), $given);
}

/**
 * Build the URL used to serve an uploaded file, optionally pointing at a
 * generated variant served by StorageController: '' = the original full-size
 * file, 'thumb' = the 400x300 crop, 'web' = the fast-loading web variant.
 * Pass $format = 'webp' to prefer the WebP copy of a variant when one exists
 * (it falls back to the original variant format if no WebP file is present).
 */
function file_url(string $filename, string $size = '', string $format = ''): string
{
    $query = in_array($size, ['thumb', 'web'], true) ? '?size=' . $size : '';

    // Version the regenerated variants by file mtime so the browser never
    // shows a stale thumbnail/web image after it is edited or recaptured.
    if ($size === 'thumb' || $size === 'web') {
        $variant = ($size === 'thumb' ? 'thumb_' : 'web_') . $filename;

        if ($format === 'webp') {
            $webp = preg_replace('/\.[^.]+$/', '.webp', $variant);
            $webpPath = config('app.uploads.dir') . '/' . $webp;

            if (is_file($webpPath)) {
                $variant = $webp;
                $query  .= '&format=webp';
            }
        }

        $mtime = @filemtime(config('app.uploads.dir') . '/' . $variant);

        if ($mtime !== false) {
            $query .= '&v=' . $mtime;
        }
    }

    // Append a time-bound HMAC for non-thumb variants so a raw URL opened in
    // DevTools or a new tab can't be saved without the token.
    if ($size !== 'thumb') {
        $token = media_token($filename);

        if ($token !== '') {
            $query .= ($query === '' ? '?' : '&') . 't=' . rawurlencode($token);
        }
    }

    return url('/files/' . rawurlencode($filename) . $query);
}

/**
 * Responsive image source set: returns a srcset string covering the thumb
 * and web variants (with their WebP versions), so browsers pick the right
 * size and format. Returns '' when the media has no image variants.
 */
function file_srcset(string $filename): string
{
    $thumb = file_url($filename, 'thumb');
    $web   = file_url($filename, 'web');

    $set = [];
    if ($thumb !== '') {
        $set[] = $thumb . ' 400w';
    }
    if ($web !== '') {
        $set[] = $web . ' 1600w';
    }

    return implode(', ', $set);
}

/**
 * Build a <picture> element that serves WebP when supported and falls back to
 * the JPEG/PNG variant otherwise, with responsive srcset sizing. $classes and
 * $attrs (e.g. loading, decoding) are applied to the inner <img>.
 */
function responsive_picture(string $filename, string $size, array $attrs = []): string
{
    $thumbWebp = file_url($filename, $size, 'webp');
    $thumbFallback = file_url($filename, $size);

    $attrString = '';
    foreach ($attrs as $key => $value) {
        if ($value === true) {
            $attrString .= ' ' . $key;
        } elseif ($value !== false && $value !== null) {
            $attrString .= ' ' . $key . '="' . e((string) $value) . '"';
        }
    }

    return '<picture>'
        . '<source type="image/webp" srcset="' . e($thumbWebp) . '">'
        . '<img src="' . e($thumbFallback) . '"' . $attrString . '>'
        . '</picture>';
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
 * Decode an image file with ImageMagick (when available) and hand the result
 * to GD as true-color pixels, returning [$resource, IMAGETYPE_JPEG] or null.
 *
 * This is the path for formats GD/getimagesize cannot read on their own --
 * notably HEIC/HEIF (iPhone), which PHP's getimagesize returns false for on
 * builds without HEIC support -- plus TIFF and AVIF. Auto-orientation is
 * applied so the pixels are already upright, and the width/type returned is
 * IMAGETYPE_JPEG so downstream variant/save code writes compact JPEG (plus
 * the separate WebP copies produced alongside).
 */
function _load_image_imagick(string $src): ?array
{
    if (!class_exists('Imagick')) {
        return null;
    }

    try {
        $im = new Imagick($src);

        if ($im->getNumberImages() < 1 || $im->getImageWidth() < 1) {
            $im->destroy();

            return null;
        }

        $im->setIteratorIndex(0);

        // Auto-orient by rotating from the EXIF orientation tag. manual
        // rotation is used because autoOrientImage() is unavailable on some
        // ImageMagick 6/php-imagick builds, and method_exists is unreliable
        // for Imagick (it dispatches all unknown methods via __call).
        $orientation = $im->getImageOrientation();

        if ($orientation === \Imagick::ORIENTATION_LEFTTOP) {
            $im->rotateImage('#00000000', 90);
        } elseif ($orientation === \Imagick::ORIENTATION_RIGHTTOP) {
            $im->rotateImage('#00000000', 180);
        } elseif ($orientation === \Imagick::ORIENTATION_RIGHTBOTTOM) {
            $im->rotateImage('#00000000', 270);
        } elseif ($orientation === \Imagick::ORIENTATION_LEFTBOTTOM) {
            $im->rotateImage('#00000000', 0);
        } elseif ($orientation === \Imagick::ORIENTATION_TOPRIGHT) {
            $im->flopImage();
        } elseif ($orientation === \Imagick::ORIENTATION_BOTTOMLEFT) {
            $im->flipImage();
        } elseif ($orientation === \Imagick::ORIENTATION_BOTTOMRIGHT) {
            $im->rotateImage('#00000000', 90);
            $im->flopImage();
        }

        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);

        // Flatten layered formats (e.g. TIFF/PDF) onto the first frame so the
        // single frame we hand to GD is the visible one. mergeImageLayers works
        // across ImageMagick 6 and 7 without deprecation notices.
        if ($im->getNumberImages() > 1 && method_exists($im, 'mergeImageLayers')) {
            $flat = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

            if ($flat !== false) {
                $im->destroy();
                $im = $flat;
            }
        }

        // Drop any alpha channel so the JPEG we output has a solid background
        // (matching the dominant photo path) rather than translucent pixels.
        $im->setImageBackgroundColor('#ffffff');
        $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

        // Encode to PNG inside ImageMagick before reading pixels back: the PNG
        // encoder is always available, whereas re-encoding a format the box can
        // only decode (HEIC has no HEVC encoder) would yield an empty blob.
        $im->setImageFormat('PNG');
        $blob = $im->getImageBlob();

        $im->destroy();
    } catch (Throwable $e) {
        return null;
    }

    if ($blob === '') {
        return null;
    }

    $image = @imagecreatefromstring($blob);

    if ($image === false) {
        return null;
    }

    if (!imageistruecolor($image)) {
        imagepalettetotruecolor($image);
    }

    return [$image, IMAGETYPE_JPEG];
}

/**
 * Whether a file can be decoded as an image for upload/storage. Uses GD's
 * getimagesize when it recognises the file, falling back to ImageMagick for
 * formats (HEIC/HEIF, AVIF, TIFF) GD cannot inspect.
 */
function image_can_decode(string $src): bool
{
    if (@getimagesize($src) !== false) {
        return true;
    }

    return _load_image_imagick($src) !== null;
}

/**
 * Measured pixel dimensions [width, height] of an image via ImageMagick, or
 * null when it cannot be decoded. Used by create_image_variants to size a
 * source that getimagesize cannot inspect (HEIC/HEIF).
 */
function _imagick_dimensions(string $src): ?array
{
    if (!class_exists('Imagick')) {
        return null;
    }

    try {
        $im = new Imagick($src);

        if ($im->getNumberImages() < 1 || $im->getImageWidth() < 1) {
            $im->destroy();

            return null;
        }

        $dims = [$im->getImageWidth(), $im->getImageHeight()];

        $im->destroy();

        return $dims;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Load an image file into a GD resource, returning [$resource, $type] or
 * [false, 0] on failure. Shared by create_thumbnail and create_web_image.
 */
function _load_image(string $src)
{
    $info = @getimagesize($src);

    // getimagesize returns false for some valid formats (HEIC/HEIF on builds
    // without HEIC support, and some AVIF/TIFF variants) that ImageMagick can
    // still decode. Try Imagick before giving up.
    if ($info === false) {
        $imagick = _load_image_imagick($src);

        return $imagick !== null ? $imagick : [false, 0];
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
    } elseif ($type === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
        $image = @imagecreatefrombmp($src);
    }

    if ($prescaled !== null) {
        @unlink($prescaled);
    }

    if ($image === false) {
        // getimagesize recognised the file (AVIF/TIFF/BMP report a type) but
        // GD still cannot decode it. Fall back to ImageMagick.
        $imagick = _load_image_imagick($src);

        return $imagick !== null ? $imagick : [false, 0];
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
 * Create a blurred, throwaway copy of an image in the system temp dir for
 * posting, leaving the source file (and any stored web variants) untouched.
 *
 * The blur is blended back over the sharp original at blurPercent/100, so
 * "25" yields a quarter-strength Gaussian blur rather than a heavy one.
 * Returns the temp file path, or null when GD cannot process the image (the
 * caller then falls back to the unblurred file). The returned temp file is the
 * caller's responsibility to unlink.
 */
function create_blurred_copy(string $src, int $blurPercent = 25): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagefilter')) {
        return null;
    }

    [$source, $type] = _load_image($src);

    if ($source === false) {
        return null;
    }

    $orientation = $type === IMAGETYPE_JPEG ? exif_orientation_of($src) : 1;
    $image = apply_exif_orientation($source, $orientation);
    imagedestroy($source);

    if ($image === false) {
        return null;
    }

    $width  = imagesx($image);
    $height = imagesy($image);
    $blurred = imagecreatetruecolor($width, $height);

    if ($blurred === false) {
        imagedestroy($image);

        return null;
    }

    imagecopy($blurred, $image, 0, 0, 0, 0, $width, $height);
    imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);

    $percent = max(0, min(100, (int) $blurPercent));

    if ($percent <= 0) {
        // No blur requested; keep the clear copy as-is.
    } elseif ($percent >= 100) {
        imagecopy($image, $blurred, 0, 0, 0, 0, $width, $height);
    } else {
        imagecopymerge($image, $blurred, 0, 0, 0, 0, $width, $height, $percent);
    }

    imagedestroy($blurred);

    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    $dest = sys_get_temp_dir() . '/blur_' . bin2hex(random_bytes(6)) . '.'
        . ($extensions[$type] ?? 'jpg');

    $saved = save_image($image, $dest, $type, 82);
    imagedestroy($image);

    return $saved && is_file($dest) ? $dest : null;
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
 * WebP variants (web_*.webp / thumb_*.webp) are produced in the same pass
 * so browsers with WebP support skip the JPEG download entirely.
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

    // getimagesize returns false for some valid formats (HEIC/HEIF on builds
    // without HEIC support, and some AVIF/TIFF variants). ImageMagick can
    // still decode them, so measure the source through Imagick and continue
    // via the GD fallback path below; _variants_gd -> _load_image handles the
    // Imagick decode itself. Genuine JPEGs keep the fast ffmpeg path.
    if ($info === false) {
        $imagickDims = _imagick_dimensions($src);

        if ($imagickDims === null) {
            return false;
        }

        $nativeJpeg = false;
        [$srcW, $srcH, $type] = [$imagickDims[0], $imagickDims[1], IMAGETYPE_JPEG];
    } else {
        $nativeJpeg = $info[2] === IMAGETYPE_JPEG;
        [$srcW, $srcH, $type] = $info;
    }

    $webpWebDest   = preg_replace('/\.[^.]+$/', '.webp', $webDest);
    $webpThumbDest = preg_replace('/\.[^.]+$/', '.webp', $thumbDest);

    if ($nativeJpeg && is_executable('/usr/bin/ffmpeg')) {
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

        $webpWebW = max(1, (int) round($effW * min(1.0, $maxDim / max($effW, $effH))));
        $webpWebH = max(1, (int) round($effH * min(1.0, $maxDim / max($effW, $effH))));

        $pre       = _exif_transpose_chain($orientation);
        $filters   = ($pre !== '' ? $pre . ',' : '') . 'split=4[a][b][c][d]'
            . ';' . $webChain
            . ";[b]scale={$thumbWidth}:{$thumbHeight}:force_original_aspect_ratio=increase,crop={$thumbWidth}:{$thumbHeight}[t]"
            . ";[c]scale={$webpWebW}:{$webpWebH}[ww]"
            . ";[d]scale={$thumbWidth}:{$thumbHeight}:force_original_aspect_ratio=increase,crop={$thumbWidth}:{$thumbHeight}[tt]";

        $cmd = escapeshellarg('/usr/bin/ffmpeg')
            . ' -y -hide_banner -loglevel error -i ' . escapeshellarg($src)
            . ' -filter_complex "' . $filters . '"'
            . ' -map "[w]" -frames:v 1 -q:v 4 ' . escapeshellarg($webDest)
            . ' -map "[t]" -frames:v 1 -q:v 5 ' . escapeshellarg($thumbDest)
            . ' -map "[ww]" -frames:v 1 -c:v libwebp -quality 80 ' . escapeshellarg($webpWebDest)
            . ' -map "[tt]" -frames:v 1 -c:v libwebp -quality 80 ' . escapeshellarg($webpThumbDest)
            . ' 2>/dev/null';

        exec($cmd, $out, $rc);

        if ($rc === 0 && is_file($webDest) && is_file($thumbDest)) {
            return true;
        }

        @unlink($webDest);
        @unlink($thumbDest);
        @unlink($webpWebDest);
        @unlink($webpThumbDest);
    }

    $ok = _variants_gd($src, $webDest, $thumbDest, $maxDim, $thumbWidth, $thumbHeight);
    _variants_gd_webp($src, $webpWebDest, $webpThumbDest, $maxDim, $thumbWidth, $thumbHeight);

    return $ok;
}

/**
 * GD-only WebP variant generation: decode once, then write the web and
 * thumbnail sizes as WebP. Used as the fallback when ffmpeg is unavailable
 * (or for non-JPEG uploads), keeping WebP parity with the ffmpeg path.
 */
function _variants_gd_webp(string $src, string $webpWebDest, string $webpThumbDest, int $maxDim, int $thumbWidth, int $thumbHeight): bool
{
    if (!function_exists('imagewebp')) {
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

    if (!imageistruecolor($source)) {
        imagepalettetotruecolor($source);
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);

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

    $webpWebOk = imagewebp($web, $webpWebDest, 80);

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
    $webpThumbOk = imagewebp($thumb, $webpThumbDest, 80);

    imagedestroy($thumb);
    imagedestroy($web);

    return $webpWebOk && $webpThumbOk;
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
