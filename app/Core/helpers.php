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
 * A writable directory for scratch files (blurred copies, media chunks,
 * frame grabs). Prefers the system temp dir, but falls back to a dedicated
 * directory under storage/ when /tmp is missing or not writable by the web
 * user — a misconfigured /tmp otherwise silently breaks media uploads with
 * errors like "Path cannot be empty".
 */
function gallery_temp_dir(): string
{
    static $dir = null;

    if ($dir !== null) {
        return $dir;
    }

    $sys = sys_get_temp_dir();
    if ($sys !== '' && is_dir($sys) && is_writable($sys)) {
        return $dir = rtrim($sys, '/');
    }

    $fallback = dirname(__DIR__, 2) . '/storage/tmp';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0775, true);
    }

    return $dir = rtrim($fallback, '/');
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
        $env  = [];
        if (is_readable($file)) {
            $contents = file_get_contents($file);
            if ($contents !== false) {
                $contents = preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
                $env = parse_ini_string($contents, false, INI_SCANNER_RAW) ?: [];
            }
        }
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
 * Build an absolute URL (scheme + host + path) from a route path. The site's
 * APP_URL (which already includes the base path, e.g.
 * https://amethyst2213.com/gallery) anchors the result; without it the
 * current request's scheme/host is used so links still work on any host.
 * Sign-off emails and newsletter bodies use this so links work inside email
 * clients (which never carry the browser's session).
 */
function absolute_url(string $path = ''): string
{
    $base   = rtrim((string) config('app.base_path'), '/');
    $appUrl = trim((string) env_value('APP_URL', ''));

    // The path may already carry the base prefix (e.g. url() output from
    // file_url()); strip it, because APP_URL already includes the base.
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }

    if ($appUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $appUrl = $scheme . '://' . $host . $base;
    }

    return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
}

/**
 * Versioned URL for a static asset under public/, cache-busted by the file's
 * own mtime.
 *
 * The admin layout already did this for admin.css, but user.css was pinned by
 * hand at ?v=33 in two layouts - so every CSS edit needed a manual bump or
 * clients kept serving the old stylesheet. One helper now, so no asset can be
 * forgotten.
 */
function asset_url(string $path): string
{
    $rel  = '/' . ltrim($path, '/');
    $file = dirname(__DIR__, 2) . '/public' . $rel;
    $ver  = is_file($file) ? (int) filemtime($file) : 0;

    return url($rel) . ($ver > 0 ? '?v=' . $ver : '');
}

/**
 * Render an email template from views/emails/ into a string buffer.
 *
 * Templates are plain PHP fragments with no chrome of their own: they emit only
 * their body rows and set $emailTitle / $headerHref / $emailFooter, which this
 * function forwards into the shared views/emails/layout.php wrapper. Before the
 * layout existed every template carried its own copy of the DOCTYPE, the
 * presentation table, the brand header and the footer, so a change to any of it
 * meant editing eight files.
 *
 * Two kinds of template are never wrapped:
 *   - anything ending in '.text' (the plain-text alternative part), and
 *   - the layout itself.
 * Pass $withLayout = false to skip wrapping for a one-off.
 *
 * Still renders correctly from the web request (admin test / send-now) AND the
 * CLI worker, because it stays a pure string function.
 */
function render_email(string $template, array $data = [], bool $withLayout = true): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../../views/emails/' . $template . '.php';
    $body = (string) ob_get_clean();

    if (!$withLayout || $template === 'layout' || substr($template, -5) === '.text') {
        return $body;
    }

    // $emailTitle / $headerHref / $emailFooter were set by the template in this
    // same scope; give them sane defaults and hand everything to the layout.
    $emailTitle  = (string) ($emailTitle ?? 'notification');
    $headerHref  = (string) ($headerHref ?? absolute_url('/'));
    $emailFooter = (string) ($emailFooter ?? 'marketing');
    $content     = $body;

    ob_start();
    require __DIR__ . '/../../views/emails/layout.php';

    return (string) ob_get_clean();
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
        // No secret configured: fail closed. Originals and web-sized variants
        // must never be served through a URL that can be replayed without a
        // signed token, so a missing key denies rather than silently passing.
        // config/validate.php refuses to boot production without this key.
        return false;
    }

    return hash_equals(media_token($path), $given);
}

/**
 * On-disk prefix of a served variant for a given size key. One source of
 * truth for file_url() (which versions URLs by the variant's mtime) and
 * StorageController (which resolves the file), so a size key can never be
 * minted by one and resolved as a different file by the other.
 * 'web_lo'/'web_hi' read their prefix from the ladder config.
 */
function media_variant_prefix(string $size): string
{
    switch ($size) {
        case 'thumb':
            return 'thumb_';
        case 'web':
            return 'web_';
        case 'blur':
            return 'blur_thumb_';
        case 'poster':
            return 'poster_';
    }

    foreach (video_ladder() as $rung) {
        if ($rung['size'] === $size) {
            return (string) $rung['prefix'];
        }
    }

    return '';
}

/**
 * Build the URL used to serve an uploaded file, optionally pointing at a
 * generated variant served by StorageController: '' = the original full-size
 * file, 'thumb' = the 400x300 crop, 'web' = the fast-loading web variant,
 * 'web_lo'/'web_hi' = the extra video ladder rungs, 'poster' = the wide
 * poster frame. Pass $format = 'webp' to prefer the WebP copy of a variant
 * when one exists (it falls back to the original variant format if no WebP
 * file is present).
 */
function file_url(string $filename, string $size = '', string $format = ''): string
{
    static $mtimeCache = [];

    $variantSizes = ['thumb', 'web', 'blur', 'poster', 'web_lo', 'web_hi'];

    $query = in_array($size, $variantSizes, true) ? '?size=' . $size : '';

    // Version the regenerated variants by file mtime so the browser never
    // shows a stale thumbnail/web image after it is edited or recaptured.
    if (in_array($size, $variantSizes, true)) {
        $variant = media_variant_prefix($size) . $filename;

        if ($format === 'webp') {
            $webp = preg_replace('/\.[^.]+$/', '.webp', $variant);
            $webpPath = config('app.uploads.dir') . '/' . $webp;

            if (is_file($webpPath)) {
                $variant = $webp;
                $query  .= '&format=webp';
            }
        } elseif ($format === 'avif') {
            // AVIF storage copy of the same variant, encoded at upload time
            // (see _make_avif). ~25-30% smaller than WebP for the grids.
            $avif = preg_replace('/\.[^.]+$/', '.avif', $variant);
            $avifPath = config('app.uploads.dir') . '/' . $avif;

            if (is_file($avifPath)) {
                $variant = $avif;
                $query  .= '&format=avif';
            }
        }

        // Memoize the stat per request: a 48-card grid calls file_url 3-4x
        // per cover, each a filemtime() syscall. A single look-up per
        // variant per request avoids ~200 stat() calls on the busiest pages.
        if (!array_key_exists($variant, $mtimeCache)) {
            $mtimeCache[$variant] = @filemtime(config('app.uploads.dir') . '/' . $variant);
        }

        $mtime = $mtimeCache[$variant];

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
 * Determine whether an uploaded filename is a video based on its extension.
 * Used to branch between image and video rendering in views.
 */
function is_video(string $filename): bool
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return in_array($extension, config('app.uploads.video_ext'), true);
}

/**
 * Detect a file's MIME type with finfo when available, or '' when it cannot
 * be determined. Shared by every upload/validation path so a sniffed type is
 * never implemented twice with different fallbacks.
 */
function sniff_mime(string $path): string
{
    if (class_exists('finfo')) {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime !== false ? $mime : '';
    }

    return '';
}

/**
 * Map a filename extension to its content type so files are streamed with
 * the correct header. Single source of truth for the upload/validation and
 * file-serving paths (previously three hand-maintained copies).
 */
function mime_for_extension(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm',
        'ogg' => 'video/ogg', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
    ];

    return $map[$extension] ?? 'application/octet-stream';
}

/**
 * Whether an mp4/mov has its moov atom at the END (mdat before moov). Browsers
 * need moov to start playback/seek, so such files are slow to load — they must
 * be remuxed with +faststart to move moov to the front.
 */
function video_needs_faststart(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = fread($fh, 2 * 1024 * 1024);
    fclose($fh);

    if ($head === false || strlen($head) < 8) {
        return false;
    }

    $pos = 0;
    $len = strlen($head);
    while ($pos + 8 <= $len) {
        $size = unpack('N', substr($head, $pos, 4))[1];
        $type = substr($head, $pos + 4, 4);

        if ($type === 'moov') {
            return false; // moov at the front -> fine
        }
        if ($type === 'mdat') {
            return true;  // media before moov -> needs faststart
        }
        if ($size < 8 || $pos + $size > $len) {
            return false;
        }
        $pos += $size;
    }

    return false;
}

/**
 * Generate the web-optimized mid ladder rung (≤1280x720 by default) next to
 * the original, so the player streams a fraction of the bandwidth instead of a
 * 1080p ~17 Mbps original. This is the synchronous upload-time rendition; the
 * lo/hi rungs are built in the background by bin/video_rendition_worker.php.
 * Settings come from the 'mid' rung in config app.video_ladder. Returns false
 * when the source is missing or the transcode fails.
 */
function create_video_web_rendition(string $src, string $dest): bool
{
    $mid = null;
    foreach (video_ladder() as $rung) {
        if ($rung['key'] === 'mid') {
            $mid = $rung;
            break;
        }
    }

    if ($mid === null) {
        return false;
    }

    return create_video_rendition($src, $dest, $mid);
}

/**
 * The configured bitrate ladder (config app.video_ladder), normalized to the
 * shape the rest of the code uses and ordered low -> high resolution. Each
 * rung always carries its config 'key' plus the size key StorageController
 * serves it under ('lo' -> 'web_lo', 'mid' -> 'web', 'hi' -> 'web_hi').
 */
function video_ladder(): array
{
    $rungs = config('app.video_ladder', []);
    if (!is_array($rungs) || $rungs === []) {
        return [];
    }

    $out = [];
    foreach ($rungs as $rung) {
        if (!is_array($rung) || empty($rung['key']) || empty($rung['prefix'])) {
            continue;
        }
        $rung['size'] = $rung['key'] === 'mid' ? 'web' : 'web_' . $rung['key'];
        $out[] = $rung;
    }

    usort($out, static fn ($a, $b) => ($a['max_w'] ?? 0) <=> ($b['max_w'] ?? 0));

    return $out;
}

/**
 * Which ladder rungs a source video actually needs: a rung is wanted only when
 * the source exceeds its target box (src_w > max_w or src_h > max_h), so a
 * 480p clip never gets a pointless 480p re-encode. The mid rung is always
 * wanted (it is the synchronous upload rendition). Returns a list of the rung
 * config arrays that apply. Pure function - unit tested directly.
 *
 * The dimensions must be the DISPLAY size (see video_display_dimensions), and
 * the box is transposed for a vertical source, so a rung means the same
 * resolution class in either orientation.
 */
function video_ladder_plan(int $srcW, int $srcH): array
{
    if ($srcW <= 0 || $srcH <= 0) {
        // Unknown dimensions: only trust the synchronous mid rung.
        $mid = array_values(array_filter(video_ladder(), static fn ($r) => $r['key'] === 'mid'));
        return $mid;
    }

    $plan = [];
    foreach (video_ladder() as $rung) {
        [$maxW, $maxH] = video_ladder_box($rung, $srcW, $srcH);
        $wants = $rung['key'] === 'mid' ? true : ($srcW > $maxW || $srcH > $maxH);
        if ($wants) {
            $plan[] = $rung;
        }
    }

    return $plan;
}

/**
 * Absolute paths of the source video's ladder variants, keyed by size key
 * ('web_lo', 'web', 'web_hi') - the same keys StorageController serves. Paths
 * are returned regardless of whether the file exists; callers stat them.
 */
function video_rung_paths(string $filename): array
{
    $dir      = rtrim((string) config('app.uploads.dir'), '/');
    $paths    = [];
    foreach (video_ladder() as $rung) {
        $paths[$rung['size']] = $dir . '/' . $rung['prefix'] . basename($filename);
    }

    return $paths;
}

/**
 * Absolute path of a video's poster frame (a wide still used as the player's
 * poster/preview image), or '' when none exists. Poster frames are 1280x720.
 */
function video_poster_path(string $filename): string
{
    $p = rtrim((string) config('app.uploads.dir'), '/') . '/poster_' . basename($filename);

    return is_file($p) ? $p : '';
}

/**
 * The poster URL of a video for the player's poster attribute, or '' when no
 * poster frame exists. Gated exactly like the web video rendition (session
 * token), served by StorageController under ?size=poster.
 */
function video_poster_url(string $filename): string
{
    return video_poster_path($filename) !== ''
        ? file_url($filename, 'poster')
        : '';
}

/**
 * The DISPLAY dimensions of a video's first video stream, in square pixels.
 *
 * A phone video is usually stored landscape with a rotation in its display
 * matrix (2400x1080 + "rotate 90"), so the coded size is NOT what the viewer
 * sees. Everything that decides an output size - the ladder, the poster, the
 * player box - has to use the display size, or a vertical clip ends up with a
 * 1280x2844 "720p" rendition: four times the intended pixels, in the wrong
 * shape, which is what put black bars around the player.
 *
 * Returns null when the file cannot be probed. The array carries:
 *   coded_w/coded_h  the size as stored in the file
 *   w/h              the DISPLAY size (rotation applied, sample aspect applied)
 *   rotation         0, 90, 180 or 270 (clockwise)
 *   sar              the sample aspect ratio as a float (1 = square pixels)
 */
function video_display_dimensions(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $ffprobe = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $raw     = [];
    $rc      = 0;
    @exec(
        escapeshellarg($ffprobe) . ' -v error -select_streams v:0 -show_streams -of json '
        . escapeshellarg($path) . ' 2>/dev/null',
        $raw,
        $rc
    );
    if ($rc !== 0 || !$raw) {
        return null;
    }

    $json   = json_decode(implode("\n", $raw), true);
    $stream = is_array($json) ? ($json['streams'][0] ?? null) : null;
    if (!is_array($stream)) {
        return null;
    }

    $codedW = (int) ($stream['width'] ?? 0);
    $codedH = (int) ($stream['height'] ?? 0);
    if ($codedW <= 0 || $codedH <= 0) {
        return null;
    }

    // Rotation: the display matrix, which ffmpeg reports as a side data entry
    // (4.4 and newer). Older files carry only the legacy "rotate" stream tag,
    // whose sign is inverted - but both are 90 or 270 for the swap that
    // matters here.
    $rotation = 0;
    foreach ((array) ($stream['side_data_list'] ?? []) as $side) {
        if (isset($side['rotation'])) {
            $rotation = (int) round((float) $side['rotation']);
            break;
        }
    }
    if ($rotation === 0 && isset($stream['tags']['rotate'])) {
        $rotation = (int) round((float) $stream['tags']['rotate']);
    }
    $rotation = ((int) round($rotation / 90) * 90) % 360;
    if ($rotation < 0) {
        $rotation += 360;
    }

    // Sample aspect ratio: anamorphic sources store 720x576 with a 16:15 SAR
    // and are displayed as 768x576.
    $sar = 1.0;
    if (!empty($stream['sample_aspect_ratio'])
        && preg_match('#^(\d+):(\d+)$#', (string) $stream['sample_aspect_ratio'], $m)
        && (int) $m[2] > 0) {
        $sar = (int) $m[1] / (int) $m[2];
    }
    if ($sar <= 0) {
        $sar = 1.0;
    }

    if ($rotation === 90 || $rotation === 270) {
        // The rotated frame is coded_h x coded_w and carries the INVERSE SAR,
        // so its display width shrinks by the SAR factor.
        $w = (int) round($codedH / $sar);
        $h = (int) round($codedW);
    } else {
        $w = (int) round($codedW * $sar);
        $h = $codedH;
    }

    return [
        'coded_w'  => $codedW,
        'coded_h'  => $codedH,
        'w'        => max(1, $w),
        'h'        => max(1, $h),
        'rotation' => $rotation,
        'sar'      => $sar,
    ];
}

/**
 * A ladder rung's target box, transposed when the source is vertical.
 *
 * A rung has to mean the same resolution class in either orientation: the
 * mid rung's 1280x720 box becomes 720x1280 for a vertical clip, which is what
 * "720p vertical" means in the industry (720 wide, 1280 tall) rather than a
 * 324x720 sliver.
 */
function video_ladder_box(array $rung, int $w, int $h): array
{
    $maxW = (int) ($rung['max_w'] ?? 0);
    $maxH = (int) ($rung['max_h'] ?? 0);

    return $h > $w ? [$maxH, $maxW] : [$maxW, $maxH];
}

/**
 * Fit a video's display size inside a target box, preserving the aspect
 * ratio, never upscaling, and always returning even dimensions (H.264
 * rejects odd ones). Pure function - unit tested directly.
 */
function video_fit_dimensions(int $w, int $h, int $maxW, int $maxH): array
{
    if ($w <= 0 || $h <= 0) {
        return [0, 0];
    }
    if ($maxW <= 0 && $maxH <= 0) {
        return [$w, $h];
    }

    $scale = 1.0;
    if ($maxW > 0) {
        $scale = min($scale, $maxW / $w);
    }
    if ($maxH > 0) {
        $scale = min($scale, $maxH / $h);
    }

    if ($scale >= 1.0) {
        // Already inside the box: never upscale. Still snapped to even
        // dimensions, because an odd-sized source cannot be encoded at all.
        return [max(2, $w - ($w % 2)), max(2, $h - ($h % 2))];
    }

    $nw = (int) floor($w * $scale);
    $nh = (int) floor($h * $scale);

    return [max(2, $nw - ($nw % 2)), max(2, $nh - ($nh % 2))];
}

/**
 * Is an existing poster frame one of the PADDED posters (the whole frame
 * shown inside a fixed 16:9 canvas) rather than an old centre crop?
 *
 * The padded poster has hard black edges along the axis it was padded on -
 * left/right for a source narrower than 16:9, top/bottom for a wider one.
 * The old centre crop has the picture's own pixels there instead. This lets
 * the rendition worker repair a cropped poster exactly once instead of
 * rebuilding it on every tick.
 */
function video_poster_is_padded(string $poster, int $srcW, int $srcH): bool
{
    if (!is_file($poster) || $srcW <= 0 || $srcH <= 0) {
        return false;
    }
    if (!function_exists('imagecreatefromjpeg')) {
        return false;
    }

    $img = @imagecreatefromjpeg($poster);
    if ($img === false) {
        return false;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 8 || $h < 8) {
        imagedestroy($img);

        return false;
    }

    // A source narrower than 16:9 is padded on the left and right; a wider
    // one is padded top and bottom.
    $vertical = ($srcW / $srcH) < (16 / 9);

    $hits  = 0;
    $total = 0;
    $step  = max(1, intdiv($h, 32));

    for ($y = 0; $y < $h; $y += $step) {
        if ($vertical) {
            for ($k = 0; $k < 4; $k++) {
                foreach ([$k, $w - 1 - $k] as $x) {
                    $c = imagecolorat($img, (int) $x, (int) $y);
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;
                    if ($r < 10 && $g < 10 && $b < 10) {
                        $hits++;
                    }
                    $total++;
                }
            }
        } else {
            for ($k = 0; $k < 4; $k++) {
                foreach ([$k, $h - 1 - $k] as $yy) {
                    // Sample across the middle third: the padding spans the
                    // full width, but the very corners can be part of a
                    // letterboxed source picture.
                    $x = intdiv($w, 3);
                    $c = imagecolorat($img, $x, (int) $yy);
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;
                    if ($r < 10 && $g < 10 && $b < 10) {
                        $hits++;
                    }
                    $total++;
                }
            }
        }
    }

    imagedestroy($img);

    return $total > 0 && $hits > $total * 0.9;
}

/**
 * Is this gallery one that must never be announced to members?
 *
 * A TEST gallery is a normal, visible gallery that simply must not be
 * announced: no "new gallery" email, no in-app bell notification, no social
 * auto-post. Working on the site means creating throwaway galleries, and
 * every one of those that went live mailed all the opted-in members the
 * title of a set that was never meant to be seen.
 *
 * Two ways to be one:
 *   - the explicit is_test flag (the admin form's tick, or a script),
 *   - a title that starts with one of app.gallery_test_patterns.
 *
 * Pure function - unit tested directly.
 *
 * @param array $gallery a gallery row (needs at least 'title'; 'is_test' is
 *                       honoured when present)
 */
function is_test_gallery(array $gallery): bool
{
    if (!empty($gallery['is_test'])) {
        return true;
    }

    $title = trim((string) ($gallery['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $title = mb_strtolower($title);

    foreach ((array) config('app.gallery_test_patterns', []) as $pattern) {
        $pattern = mb_strtolower(trim((string) $pattern));
        if ($pattern === '' || !str_starts_with($title, $pattern)) {
            continue;
        }
        // The next character must not be a letter, so "Test Gallery" matches
        // but "Testament" does not.
        $next = mb_substr($title, mb_strlen($pattern), 1);
        if ($next === '' || !preg_match('/^\p{L}$/u', $next)) {
            return true;
        }
    }

    return false;
}

/**
 * The DISPLAY size of any uploaded media file, in pixels.
 *
 * For a video that is the size the viewer sees: the coded size with the
 * rotation in the display matrix applied (see video_display_dimensions). For
 * an image it is the size after the EXIF orientation is applied, which is
 * what create_image_variants() bakes into the pixels.
 *
 * Returns null when the file cannot be measured. Pure enough to be trusted
 * from the upload path, where a failure must never break the upload.
 */
function media_display_dimensions(string $path, bool $isVideo): ?array
{
    if ($isVideo) {
        $dim = video_display_dimensions($path);
        if ($dim === null) {
            return null;
        }

        return ['w' => (int) $dim['w'], 'h' => (int) $dim['h'], 'rotation' => (int) $dim['rotation']];
    }

    $info = @getimagesize($path);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        // getimagesize cannot read HEIC/AVIF on every build; ImageMagick can.
        $info = _imagick_dimensions($path);
        if ($info === null) {
            return null;
        }
    }

    $w = (int) ($info[0] ?? 0);
    $h = (int) ($info[1] ?? 0);
    if ($w <= 0 || $h <= 0) {
        return null;
    }

    // EXIF orientations 5-8 store the picture sideways.
    if (exif_orientation_of($path) >= 5) {
        [$w, $h] = [$h, $w];
    }

    return ['w' => $w, 'h' => $h, 'rotation' => 0];
}

/**
 * Build one ladder rung from a source video using the rung's encoder settings.
 * Fits the source's DISPLAY size inside the rung's target box, preserving the
 * aspect ratio and never upscaling, and writes a faststart MP4 to $dest.
 * Returns false when the source is missing or the transcode fails. This is the
 * generic worker used for every ladder rung (lo/mid/hi).
 */
function create_video_rendition(string $src, string $dest, array $rung, int $timeout = 0): bool
{
    if (!is_file($src)) {
        return false;
    }

    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    $vf     = '';

    // Size the output from the DISPLAY dimensions. ffmpeg's default
    // -autorotate transposes the frame BEFORE -vf runs, so the filter sees
    // the upright picture and the target has to be expressed in the same
    // (upright, square-pixel) terms. The old code probed the CODED size and
    // scaled a single axis, which turned a 2400x1080 phone clip into a
    // 1280x2844 "720p" rendition - four times the intended pixels, and the
    // reason portrait videos played in a narrow strip with black bars around
    // them.
    $dim = video_display_dimensions($src);
    if ($dim !== null) {
        [$boxW, $boxH] = video_ladder_box($rung, $dim['w'], $dim['h']);
        [$tw, $th]      = video_fit_dimensions($dim['w'], $dim['h'], $boxW, $boxH);
        if ($tw > 0 && $th > 0 && ($tw !== $dim['w'] || $th !== $dim['h'])) {
            // setsar=1 makes the output square-pixel: without it ffmpeg
            // carries a rounded SAR (6399:6400) that skews the picture.
            $vf = 'scale=' . $tw . ':' . $th . ',setsar=1';
        }
    }

    $crf     = (int) ($rung['crf'] ?? 28);
    $maxrate = (string) ($rung['maxrate'] ?? '4M');
    $bufsize = (string) ($rung['bufsize'] ?? '8M');
    $ab      = (string) ($rung['ab'] ?? '96k');

    // $timeout 0 = unbounded (upload path, already inside set_time_limit(0)).
    // The background worker passes a bound: a 500 MB / 7-minute source can take
    // 30+ minutes to re-encode on a loaded box, and one such file must not
    // hold a cron slot (and an ffmpeg guard permit) that whole time.
    $cmd = ($timeout > 0 ? 'timeout ' . $timeout . ' ' : '') . escapeshellarg($ffmpeg) . ' -nostdin -y -i ' . escapeshellarg($src)
        . ($vf !== '' ? ' -vf ' . escapeshellarg($vf) : '')
        // The rotation is baked into the pixels by -autorotate; clearing the
        // tag stops a player from rotating the picture a second time.
        . ' -metadata:s:v:0 rotate=0'
        . ' -c:v libx264 -preset veryfast -crf ' . $crf . ' -maxrate ' . escapeshellarg($maxrate) . ' -bufsize ' . escapeshellarg($bufsize)
        . ' -c:a aac -b:a ' . escapeshellarg($ab) . ' -movflags +faststart -f mp4 ' . escapeshellarg($dest) . ' 2>&1';

    @exec($cmd, $out, $rc);

    return $rc === 0 && is_file($dest);
}

/**
 * Generate a short public sample clip (first ~12s, ≤480p) from a video for
 * search-engine indexing (Google Video). The full video stays behind the
 * membership gate; only this small preview is served publicly. Returns false
 * when the source is missing or the extraction fails.
 */
function create_video_sample_clip(string $src, string $dest): bool
{
    if (!is_file($src)) {
        return false;
    }

    $dir = dirname($dest);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    // Fit within 480x480 preserving aspect, then snap both dimensions to even
    // (H.264 requires even width/height) without upscaling, then apply a heavy
    // boxblur so no recognizable content leaks through the public clip. The
    // filter is shell-quoted: the '*' glob would otherwise be expanded.
    $vf = 'scale=480:480:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2,boxblur=30:2';
    $cmd = escapeshellarg($ffmpeg) . ' -nostdin -y -i ' . escapeshellarg($src)
        . ' -t 12 -vf ' . escapeshellarg($vf)
        . ' -c:v libx264 -preset veryfast -crf 30 -maxrate 1.5M -bufsize 3M'
        . ' -c:a aac -b:a 96k -movflags +faststart -f mp4 ' . escapeshellarg($dest) . ' 2>&1';

    @exec($cmd, $out, $rc);

    if ($rc === 0 && is_file($dest)) {
        @chmod($dest, 0644);
        return true;
    }

    @unlink($dest);

    return false;
}

/**
 * Absolute path of a video's public sample clip on disk ('' if missing).
 * Clips live in uploads/previews/ keyed by the source filename, mirroring
 * the thumb_/web_ variant naming so a regenerated source gets a fresh clip.
 */
function video_sample_path(string $filename): string
{
    return rtrim((string) config('app.uploads.dir'), '/') . '/previews/preview_' . basename($filename);
}

/**
 * Public URL of a video's sample clip for search engines, or '' when the
 * clip has not been generated yet. Crawlable — never gated by session or
 * membership.
 */
function video_sample_url(int $photoId, string $filename): string
{
    return is_file(video_sample_path($filename)) ? url('/previews/' . (int) $photoId) : '';
}

/**
 * Remux a video with `-movflags +faststart` (stream copy — no re-encode, no
 * quality loss) so the moov atom sits at the front and browsers can start
 * playback and seek immediately. No-op when the file is already faststart.
 * Returns true when the file was remuxed.
 */
function faststart_video_if_needed(string $path): bool
{
    if (!video_needs_faststart($path)) {
        return false;
    }

    $tmp = $path . '.faststart.tmp';
    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    // -f mp4 forces the mp4 muxer because the temp filename's extension is
    // not .mp4 (ffmpeg would otherwise reject the unknown muxer).
    $cmd = escapeshellarg($ffmpeg) . ' -nostdin -y -i ' . escapeshellarg($path)
        . ' -c copy -movflags +faststart -f mp4 ' . escapeshellarg($tmp) . ' 2>&1';
    @exec($cmd, $out, $rc);

    if ($rc === 0 && is_file($tmp)) {
        @chmod($tmp, 0644);
        if (@rename($tmp, $path)) {
            return true; // atomic overwrite on POSIX
        }
    }

    @unlink($tmp);

    return false;
}

/**
 * Probe a video's duration in seconds with ffprobe (0 when it cannot be read).
 */
function video_duration_seconds(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }

    $ffprobe = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $probe   = [];
    $rc      = 0;

    @exec(
        escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($path) . ' 2>/dev/null',
        $probe,
        $rc
    );

    if ($rc === 0 && isset($probe[0])) {
        return max(0, (int) round((float) $probe[0]));
    }

    return 0;
}

/**
 * Whether a file actually contains a decodable video stream, probed with
 * ffprobe (used to reject corrupt/polyglot uploads and to sanity-check
 * live recordings). Empty string when there is no probe or no video stream.
 */
function video_has_stream(string $path): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }

    $ffprobe = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $probe   = [];
    $rc      = 0;

    // nokey output prints just the value ("video") without the trailing comma
    // that -of csv=p=0 adds on newer ffprobe (6.x), which broke the === check.
    @exec(
        escapeshellarg($ffprobe) . ' -v error -select_streams v:0 -show_entries stream=codec_type -of default=noprint_wrappers=1:nokey=1 '
        . escapeshellarg($path) . ' 2>/dev/null',
        $probe,
        $rc
    );

    return $rc === 0 && rtrim(trim(implode('', $probe)), ',') === 'video';
}

/**
 * The per-request CSP nonce for script tags ('' on CLI). Used by the layouts
 * and the report-only CSP emitted by the front controller.
 */
function csp_nonce(): string
{
    return defined('CSP_NONCE') ? (string) CSP_NONCE : '';
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
 * Validate a relative, same-site "return to" URL used for back links that
 * preserve listing/search context. Returns null when the value is missing or
 * unsafe (absolute URL, scheme/host present, or outside this app's base path).
 */
function safe_return_to(mixed $value): ?string
{
    if (!is_string($value) || $value === '' || strpos($value, '//') === 0) {
        return null;
    }

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || empty($parts['path'])) {
        return null;
    }

    $base = rtrim((string) config('app.base_path'), '/');
    if ($base !== '' && strpos($parts['path'], $base . '/') !== 0 && $parts['path'] !== $base) {
        return null;
    }

    return $value;
}

/**
 * Turn arbitrary text into a URL-friendly slug (lowercase, dashes instead of
 * spaces/symbols). Used to build clean category URLs. Optional $max caps the
 * length (e.g. short traffic-link codes) and $fallback is returned when the
 * slug would otherwise be empty (e.g. a safe theme filename).
 */
function slugify(string $text, ?int $max = null, ?string $fallback = null): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $slug = trim((string) $text, '-');

    if ($max !== null && mb_strlen($slug) > $max) {
        $slug = mb_substr($slug, 0, $max);
    }

    return $slug !== '' ? $slug : (string) $fallback;
}

/**
 * Human-readable byte size in the largest unit where the value is at least
 * one (B, KB, MB, GB, TB, PB). e.g. 3,563,759,256 -> "3.3 GB",
 * 193,044,375 -> "184.1 MB", 4,600 -> "4.5 KB", 900 -> "900 B".
 */
function format_bytes(float|int $bytes, int $precision = 1): string
{
    $bytes = max(0, (float) $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    if ($bytes < 1024) {
        return number_format($bytes, 0) . ' B';
    }

    $i = (int) floor(log($bytes, 1024));
    $i = min($i, count($units) - 1);

    return number_format($bytes / (1024 ** $i), $precision) . ' ' . $units[$i];
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
 * The site-wide display timezone (set by admins on the settings page).
 * Falls back to UTC when nothing has been configured.
 */
function site_timezone(): string
{
    return \App\Models\SiteConfig::timezone();
}

/**
 * The timezone dropdown options used on the settings page: the common
 * regions plus UTC (same set as the emailer's schedule timezone).
 *
 * @return array<int, array{0: string, 1: string}>
 */
function site_timezones(): array
{
    $tzs = [];

    foreach (\DateTimeZone::listIdentifiers() as $tz) {
        if (preg_match('/^((Africa|America|Antarctica|Arctic|Asia|Atlantic|Australia|Europe|Indian|Pacific)\/)/', $tz) === 1) {
            $tzs[] = [$tz, $tz];
        }
    }

    return $tzs;
}

/**
 * Format a date/time in the site-wide display timezone. Accepts a Unix
 * timestamp (int) or a parseable datetime string. Strings are treated as
 * UTC (storage is UTC throughout) and converted to the configured timezone,
 * so a returned empty string only happens for unparseable input.
 */
function tzdate(string $format, $when = null): string
{
    if ($when === null) {
        $when = time();
    }

    try {
        $dt = is_int($when)
            ? new \DateTimeImmutable('@' . $when)
            : new \DateTimeImmutable((string) $when . ' UTC');
        return $dt->setTimezone(new \DateTimeZone(site_timezone()))->format($format);
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Normalize a datetime-local picker value (site timezone) to a UTC MySQL
 * DATETIME string, or null when blank/unparseable. Single source of truth
 * for every schedule/publish picker (gallery publish, auto-poster, chat
 * broadcasts) so the same instant is stored regardless of the admin surface.
 */
function normalize_local_datetime(?string $value, string $timezone = ''): ?string
{
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }

    $tz = $timezone !== '' ? $timezone : site_timezone();

    $v = str_replace('T', ' ', $v);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(:\d{2})?$/', $v, $m)) {
        return null;
    }

    $parsed = $m[1] . (isset($m[2]) ? $m[2] : ':00');
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $parsed, new \DateTimeZone($tz));
    if ($dt === false) {
        return null;
    }

    return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/**
 * Whether a datetime-local value (interpreted in $timezone) is a valid,
 * strictly-future UTC moment. Shared "scheduled in the future" guard.
 */
function is_future_local_datetime(?string $value, string $timezone = ''): bool
{
    $utc = normalize_local_datetime($value, $timezone);

    return $utc !== null && $utc > gmdate('Y-m-d H:i:s');
}

/**
 * The shared SSE long-poll preamble: event-stream headers, no buffering, and
 * the output-buffer drain so PHP streams instead of holding the whole
 * response in memory. Callers still decide whether to session_write_close().
 */
function start_sse(): void
{
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', 'off');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
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

    // ImageMagick is only ever correct for images. A video path would trigger
    // the ffmpeg delegate + /tmp/magick-* + webp write storm - bail out before
    // opening the file.
    if (is_video($src)) {
        return null;
    }

    try {
        $im = new Imagick($src);

        if ($im->getNumberImages() < 1 || $im->getImageWidth() < 1) {
            $im->destroy();

            return null;
        }

        if ($im->getImageWidth() * $im->getImageHeight() > 75000000) {
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
    // Videos must never be handed to ImageMagick: its ffmpeg delegate would
    // spawn a guarded ffmpeg, churn /tmp/magick-* temps and write enormous
    // webp files on every request that reaches the on-demand variant path
    // (e.g. ?size=web on a video whose rendition is missing). Video renditions
    // are made by ffmpeg at upload/import time - never ImageMagick.
    if (is_video($src)) {
        return false;
    }

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

    // Same video guard as _load_image_imagick: never let ImageMagick open a
    // video (ffmpeg-delegate / /tmp/magick-* / webp storm).
    if (is_video($src)) {
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

        if ($dims[0] * $dims[1] > 75000000) {
            return null;
        }

        return $dims;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Load an image file into a GD resource, returning [$resource, $type] or
 * [false, 0] on failure. Shared by create_thumbnail.
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

    // Decompression-bomb guard: reject absurd pixel counts before decoding
    // (75 MP covers today's high-resolution phone/DSLR captures - e.g. 64 MP
    // sensors - while staying far below the memory blow-up point of
    // GD/Imagick).
    if ((int) $info[0] * (int) $info[1] > 75000000) {
        return [false, 0];
    }

    // Huge photos (e.g. 60+ megapixel camera captures) are extremely slow and
    // memory-heavy to decode and resample with GD alone. When the source is
    // larger than a working cap, pre-scale it with ffmpeg (which uses fast,
    // optimized resampling) into a temporary file, then load that smaller file
    // with GD. This makes uploads of very large images complete quickly.
    $maxWorking = 4000;
    $prescaled  = null;
    // Remember the real source: the pre-scale below may swap $src for a temp
    // file, and the ImageMagick fallback further down must never read a temp
    // path that is about to be deleted.
    $originalSrc = $src;

    if (max($info[0], $info[1]) > $maxWorking
        && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
        $prescaled = tempnam(gallery_temp_dir(), 'imgload');

        if ($prescaled !== false) {
            // ffmpeg needs a recognisable extension (or an explicit -f) to
            // pick the output muxer; tempnam() returns an extension-less path.
            $prescaled .= '.jpg';

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

    if ($image === false) {
        // getimagesize recognised the file (AVIF/TIFF/BMP report a type) but
        // GD still cannot decode it. Fall back to ImageMagick - against the
        // ORIGINAL source, never the pre-scale temp: that temp is deleted in
        // the next line, so pointing the fallback at it would read a path that
        // no longer exists and silently fail formats ImageMagick can actually
        // decode (HEIC/HEIF).
        if ($prescaled !== null) {
            @unlink($prescaled);
            $prescaled = null;
        }

        $imagick = _load_image_imagick($originalSrc);

        return $imagick !== null ? $imagick : [false, 0];
    }

    if ($prescaled !== null) {
        @unlink($prescaled);
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
    } else {
        // Same coverage gap as save_image(): BMP (and anything else GD can
        // decode but not re-encode) has no branch, so the thumbnail write
        // failed and the grid tile 404'd. JPEG bytes keep it serving.
        $saved = imagejpeg($thumb, $dest, 85);
    }

    imagedestroy($source);
    imagedestroy($thumb);

    return $saved;
}

/**
 * Create a blurred, throwaway copy of an image in the system temp dir for
 * posting, leaving the source file (and any stored web variants) untouched.
 *
 * The blur is a downscale-then-upscale (large effective radius, so it is
 * clearly visible and survives X's re-encode, which re-sharpens uploads),
 * blended back over the sharp original at blurPercent/100. Stronger blurPercent
 * both enlarges the downscale divisor (2 -> 100, capping at 12x) and blends a
 * larger share of the blurred base. Returns the temp file path, or null when
 * GD cannot process the image (the caller then falls back to the unblurred
 * file). The returned temp file is the caller's responsibility to unlink.
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
    $percent = max(0, min(100, (int) $blurPercent));

    // Downscale by a wide divisor, smooth it, then scale back up: the resample
    // blurs across a very wide neighbourhood (unlike the small-radius GD
    // gaussian kernel). At 100% the image is shrunk ~16x so the upscaled copy
    // is uniformly soft and unrecognisable while still hinting at the subject.
    $divisor = 4 + (int) round(($percent / 100) * 12);
    $divisor = max(4, min(20, $divisor));
    $smallW  = max(6, (int) round($width / $divisor));
    $smallH  = max(6, (int) round($height / $divisor));
    $small   = imagecreatetruecolor($smallW, $smallH);

    if ($small === false) {
        imagedestroy($image);

        return null;
    }

    imagecopyresampled($small, $image, 0, 0, 0, 0, $smallW, $smallH, $width, $height);
    imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
    imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
    imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);

    $blurred = imagecreatetruecolor($width, $height);

    if ($blurred === false) {
        imagedestroy($small);
        imagedestroy($image);

        return null;
    }

    imagecopyresampled($blurred, $small, 0, 0, 0, 0, $width, $height, $smallW, $smallH);
    imagedestroy($small);

    if ($percent >= 100) {
        // Fully blurred preview: the sharp original never bleeds through.
        imagecopy($image, $blurred, 0, 0, 0, 0, $width, $height);
    } elseif ($percent > 0) {
        imagecopymerge($image, $blurred, 0, 0, 0, 0, $width, $height, $percent);
    }

    imagedestroy($blurred);

    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    $dest = gallery_temp_dir() . '/blur_' . bin2hex(random_bytes(6)) . '.'
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
    } else {
        // Formats GD can decode but not re-encode (BMP, ...) land here.
        // Returning false made variant generation fail silently, so the
        // photo was stored with no web_/thumb_ rendition and the site then
        // served the FULL original through ?size=web (and 404'd the grid
        // tile). Write JPEG bytes instead: the file keeps its original
        // name, and StorageController sniffs the actual content type.
        $saved = imagejpeg($image, $dest, $quality);
    }

    return $saved;
}

/**
 * Opacity the watermark is drawn at (0-1). Kept in one place so the GD alpha
 * fade and the ffmpeg colorchannelmixer value can never drift apart.
 */
const WATERMARK_OPACITY = 0.20;

/** Smallest / largest the watermark logo is ever drawn (px wide). */
const WATERMARK_MIN_WIDTH = 80;
const WATERMARK_MAX_WIDTH = 320;

/**
 * Resolve the theme's title image (what the admin uploads in /admin/theme)
 * to an absolute filesystem path. Theme paths are web-relative to the site
 * root: '/assets/...' lives under public/, '/storage/...' under the app root.
 * Returns null when the file is missing or unreadable, so callers can fail
 * loudly instead of silently watermarking nothing.
 */
function watermark_logo_path(): ?string
{
    $rel = (string) \App\Models\Theme::titleImage(App\Models\Theme::SCOPE_SITE);
    $rel = trim($rel);

    if ($rel === '' || strpos($rel, '://') !== false) {
        return null;
    }

    $root  = dirname(__DIR__, 2);
    $path  = '/' . ltrim($rel, '/');
    $full  = strpos($path, '/public/') === 0
        ? $root . $path
        : ($root . '/public' . $path);

    return is_file($full) && is_readable($full) ? $full : null;
}

/**
 * Watermark placement + size for one piece of media. The logo is drawn once,
 * bottom-right, scaled to 15% of the media width (aspect preserved) and
 * clamped to MIN_WATERMARK_WIDTH..MAX_WATERMARK_WIDTH (and to the media width
 * minus padding, so it can never hang off the edge). Media too small to hold a
 * legible logo returns null - the caller skips it.
 */
function watermark_geometry(int $mediaW, int $mediaH, int $logoW, int $logoH, ?array &$skip = null): ?array
{
    $min = WATERMARK_MIN_WIDTH;             // 80px
    $max = WATERMARK_MAX_WIDTH;             // 320px
    $pad = max(12, (int) round($mediaW * 0.02));

    $room = $mediaW - (2 * $pad);
    if ($room < $min) {
        $skip = 'media_narrow';
        return null;
    }

    // Scale to 15% of the media width, then clamp.
    $wmW = (int) round($mediaW * 0.15);
    $wmW = max($min, min($max, $wmW));
    $wmW = min($wmW, $room);

    $scale = $wmW / max(1, $logoW);
    $wmH   = max(1, (int) round($logoH * $scale));

    return [
        'w'    => $wmW,
        'h'    => $wmH,
        'x'    => $mediaW - $pad - $wmW,
        'y'    => $mediaH - $pad - $wmH,
        'pad'  => $pad,
    ];
}

/**
 * Scale the alpha channel of a truecolor GD image by $factor (0 = invisible,
 * 1 = untouched). Used to fade the watermark logo to WATERMARK_OPACITY: GD's
 * imagecopymerge() destroys a source alpha channel, so the fade is done on the
 * pixels of the (tiny) logo canvas instead and then composited with
 * imagecopy(). Values: alpha 0 = opaque, 127 = transparent, so the visible
 * fraction becomes factor * original.
 */
function watermark_fade_alpha($image, float $factor)
{
    $w = imagesx($image);
    $h = imagesy($image);
    $floor = (int) round(127 * (1 - $factor));

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($image, $x, $y);
            $a = ($c >> 24) & 0xFF;
            if ($a === 0 && $factor >= 1) {
                continue;
            }
            $newA = $floor + (int) round($a * $factor);
            if ($newA === $a || $newA > 127) {
                continue;
            }
            $color = imagecolorallocatealpha(
                $image,
                ($c >> 16) & 0xFF,
                ($c >> 8) & 0xFF,
                $c & 0xFF,
                min(127, max(0, $newA))
            );
            imagesetpixel($image, $x, $y, $color);
        }
    }
}

/**
 * Burn a semi-transparent copy of the title image into a video (bottom-right)
 * by re-encoding it with ffmpeg's overlay filter. $dest must carry the SAME
 * extension as $src so ffmpeg picks the muxer that matches what the site serves
 * (a .webm file holding MP4 bytes would be sent to browsers as video/webm and
 * fail to play). Returns true only when the output exists and was produced
 * with a clean exit code; the caller swaps it in on success.
 */
function burn_video_watermark(string $src, string $dest, ?string $logoPath = null): bool
{
    if (!is_file($src)) {
        return false;
    }

    $logoPath = $logoPath ?? watermark_logo_path();

    if ($logoPath === null) {
        return false;
    }

    // SVG (and anything else neither GD nor this ffmpeg build can decode)
    // cannot be rasterised here, so refuse rather than emit an unwatermarked
    // copy that looks like a success.
    $logoInfo = @getimagesize($logoPath);
    if ($logoInfo === false) {
        return false;
    }

    [$logoW, $logoH] = $logoInfo;

    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';

    // Probe the real frame size so the logo size/placement are computed
    // against the pixels (rotation metadata can differ from the container).
    $probeOut = [];
    $probeRc  = 0;
    @exec(
        escapeshellarg('/usr/bin/ffprobe') . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 '
        . escapeshellarg($src) . ' 2>/dev/null',
        $probeOut,
        $probeRc
    );

    $srcW = 1920;
    $srcH = 1080;
    if ($probeRc === 0 && isset($probeOut[0]) && strpos($probeOut[0], ',') !== false) {
        $dims = array_map('intval', explode(',', (string) $probeOut[0]));
        if (($dims[0] ?? 0) > 0 && ($dims[1] ?? 0) > 0) {
            [$srcW, $srcH] = [$dims[0], $dims[1]];
        }
    }

    $geo = watermark_geometry($srcW, $srcH, $logoW, $logoH, $skip);
    if ($geo === null) {
        return false;
    }

    // WebM has to stay WebM (libvpx/vorbis); every other accepted container is
    // fine with H.264 + AAC. The destination extension decides both the muxer
    // (ffmpeg infers it from the output name) and the codec set, so a temp file
    // that loses the extension silently breaks the encode.
    $ext = strtolower((string) pathinfo($dest, PATHINFO_EXTENSION));

    $h264Containers = ['mp4', 'm4v', 'mov', 'avi', 'mkv', 'ts', 'm2ts', 'mts', 'flv', '3gp', '3g2', 'mpg', 'mpeg', 'wmv', 'asf', 'vob', 'm2v'];
    if ($ext === 'webm') {
        $extCodec = '-c:v libvpx -b:v 1.5M -c:a libvorbis';
    } elseif (in_array($ext, $h264Containers, true)) {
        $extCodec = '-c:v libx264 -preset veryfast -crf 23 -c:a aac -movflags +faststart';
    } else {
        // Unknown/unsupported container (e.g. .ogg): refuse instead of writing
        // an unwatermarked copy that looks like a success, and instead of
        // putting the wrong container in a file we would serve under this name.
        return false;
    }

    $timeoutBin = trim((string) (shell_exec('command -v timeout') ?: ''));
    $prefix     = $timeoutBin !== '' ? 'timeout 3600 ' : '';

    // The logo is faded to WATERMARK_OPACITY via its alpha channel and then
    // overlaid so the video's own pixels show through.
    $alpha = rtrim(rtrim(number_format(WATERMARK_OPACITY, 2, '.', ''), '0'), '.');
    $filter = '[1:v]scale=' . $geo['w'] . ':' . $geo['h']
        . ',format=rgba,colorchannelmixer=aa=' . $alpha . '[wm];'
        . '[0:v][wm]overlay=' . $geo['x'] . ':' . $geo['y'] . ':format=auto,format=yuv420p[v]';

    $cmd = $prefix . escapeshellarg($ffmpeg) . ' -nostdin -y -hide_banner -loglevel error'
        . ' -i ' . escapeshellarg($src)
        . ' -i ' . escapeshellarg($logoPath)
        . ' -filter_complex ' . escapeshellarg($filter)
        . ' -map "[v]" -map 0:a? ' . $extCodec
        . ' ' . escapeshellarg($dest) . ' 2>&1';

    exec($cmd, $lines, $status);

    if ($status !== 0 || !is_file($dest) || filesize($dest) < 1024) {
        @unlink($dest);
        return false;
    }

    // moov first so the watermarked copy streams like the original.
    faststart_video_if_needed($dest);

    return true;
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
            . ' -map_metadata -1'
            . ' -filter_complex "' . $filters . '"'
            . ' -map "[w]" -frames:v 1 -q:v 4 ' . escapeshellarg($webDest)
            . ' -map "[t]" -frames:v 1 -q:v 5 ' . escapeshellarg($thumbDest)
            . ' -map "[ww]" -frames:v 1 -c:v libwebp -quality 80 ' . escapeshellarg($webpWebDest)
            . ' -map "[tt]" -frames:v 1 -c:v libwebp -quality 80 ' . escapeshellarg($webpThumbDest)
            . ' 2>/dev/null';

        exec($cmd, $out, $rc);

        // All four outputs must exist: this ffmpeg invocation also writes the
        // WebP variants, and returning success on JPEG alone left the grids
        // without their web_*.webp / thumb_*.webp copies whenever libwebp was
        // missing from the encoder while the return code still came back 0.
        if ($rc === 0
            && is_file($webDest) && is_file($thumbDest)
            && is_file($webpWebDest) && is_file($webpThumbDest)) {
            _make_avif($webDest);
            _make_avif($thumbDest);

            return true;
        }

        @unlink($webDest);
        @unlink($thumbDest);
        @unlink($webpWebDest);
        @unlink($webpThumbDest);
    }

    $ok = _variants_gd($src, $webDest, $thumbDest, $maxDim, $thumbWidth, $thumbHeight);
    _variants_gd_webp($src, $webpWebDest, $webpThumbDest, $maxDim, $thumbWidth, $thumbHeight);
    _make_avif($webDest);
    _make_avif($thumbDest);

    return $ok;
}

/**
 * Encode an AVIF still copy of a finished variant (web_*.jpg / thumb_*.jpg)
 * via the bundled ffmpeg's libsvtav1. ~25-30% smaller than the WebP copy for
 * the image grids; skipped silently when the source is missing or ffmpeg is
 * unavailable so a generation failure can never break an upload.
 */
function _make_avif(string $variant): void
{
    if (!is_file($variant) || !is_executable('/usr/bin/ffmpeg')) {
        return;
    }

    $avif = preg_replace('/\.[^.]+$/', '.avif', $variant);
    if (is_file($avif)) {
        return;
    }

    exec(
        escapeshellarg('/usr/bin/ffmpeg') . ' -y -hide_banner -loglevel error -i '
        . escapeshellarg($variant)
        . ' -frames:v 1 -c:v libsvtav1 -preset 5 -crf 34 -still-picture 1 '
        . escapeshellarg($avif) . ' 2>/dev/null'
    );
}

/**
 * Encode a WebP copy of a finished variant next to it.
 *
 * The grid serves AVIF and WebP through a <picture> block, and a variant
 * that has no WebP copy falls back to its JPEG - which is what the guest
 * grid (the blurred covers) used to do for every single cover.
 */
function _make_webp(string $variant): void
{
    if (!is_file($variant)) {
        return;
    }

    $webp = preg_replace('/\.[^.]+$/', '.webp', $variant);
    if (!is_string($webp) || $webp === $variant || is_file($webp)) {
        return;
    }

    if (is_executable('/usr/bin/ffmpeg')) {
        exec(
            escapeshellarg('/usr/bin/ffmpeg') . ' -y -hide_banner -loglevel error -i '
            . escapeshellarg($variant)
            . ' -frames:v 1 -c:v libwebp -quality 80 '
            . escapeshellarg($webp) . ' 2>/dev/null'
        );

        if (is_file($webp)) {
            return;
        }
    }

    // GD can do it too, when the extension is one it can read.
    $image = @imagecreatefromstring((string) @file_get_contents($variant));
    if ($image === false || !function_exists('imagewebp')) {
        if ($image !== false) {
            imagedestroy($image);
        }

        return;
    }
    @imagewebp($image, $webp, 80);
    imagedestroy($image);
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

    // Check the web-variant write: discarding its result meant a failed write
    // still returned the thumbnail's success, so the gallery served the FULL
    // original through ?size=web and the caller believed it was fine.
    if (!save_image($web, $webDest, $type, 82)) {
        imagedestroy($web);

        return false;
    }

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
 * thumbnail. Samples several offsets (1s plus 10/25/40/55/70% of the runtime)
 * and keeps the sharpest frame, so an auto-captured thumbnail is no longer
 * whichever frame happens to sit at the 1-second mark (often a black fade-in).
 * Falls back to 0s when the video is too short to have a frame at 1s.
 */
/* Sharpness at or above this counts as "a real frame" (see the note in
   create_video_thumbnail's loop); below it we assume a black/blurred opening
   and pick the sharpest sampled moment instead. */
const VIDEO_FRAME_SHARP_FLOOR = 6.0;

function create_video_thumbnail(string $src, string $dest, int $width, int $height, string $fit = 'crop'): bool
{

    $duration = video_duration_seconds($src);

    if ($duration >= 3) {
        $best   = null;
        $bestS  = -1.0;
        $scratch = $dest . '.sharp.' . getmypid() . '.jpg';

        foreach (array_unique([1, (int) round($duration * 0.10), (int) round($duration * 0.25),
                               (int) round($duration * 0.40), (int) round($duration * 0.55),
                               (int) round($duration * 0.70)]) as $second) {
            if ($second >= $duration) {
                $second = $duration - 1;
            }
            // A short per-probe budget: this pass only RANKS frames, and the
            // winner is extracted again with the full budget afterwards. Six
            // probes at the default 60s would make one stuck source cost the
            // worker seven minutes before it moved on.
            if ($second < 0 || !create_video_frame($src, $scratch, $width, $height, $second, 20, $fit)) {
                continue;
            }
            $score = image_sharpness_score($scratch);
            if ($score > $bestS) {
                $bestS  = $score;
                $best   = $second;
            }
            // The 1s frame is what this site has always used, and on close-up
            // handheld footage the "sharpest" frame is often an unrepresentative
            // extreme close-up. Keep today's choice unless it is genuinely bad
            // (a black fade-in scores near zero), and only then reach for a
            // sharper moment further in.
            if ($second === 1 && $score >= VIDEO_FRAME_SHARP_FLOOR) {
                $best  = 1;
                $bestS = $score;
                break;
            }
        }

        if ($best !== null) {
            // Re-extract the winner cleanly (the scratch file is a JPEG probe)
            // rather than moving the probe, so the final file is written by
            // the same single code path as every other frame capture.
            @unlink($scratch);
            return create_video_frame($src, $dest, $width, $height, $best, 60, $fit);
        }

        @unlink($scratch);
    }

    foreach ([1, 0] as $second) {
        if (create_video_frame($src, $dest, $width, $height, $second, 60, $fit)) {
            return true;
        }
    }

    return false;
}

/**
 * Rough sharpness score of an image: mean absolute Laplacian response on a
 * greyscale downscale. Cheap (GD only) and good enough to rank a handful of
 * candidate frames - a title card or fade-in scores near zero, a detailed
 * frame scores high. Returns 0.0 when the file cannot be read.
 */
function image_sharpness_score(string $path): float
{
    if (!is_file($path)) {
        return 0.0;
    }

    $img = @imagecreatefromstring((string) @file_get_contents($path));
    if ($img === false) {
        return 0.0;
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 3 || $h < 3) {
        imagedestroy($img);
        return 0.0;
    }

    // Downscale first: the score only ranks frames, so a 64px wide sample is
    // plenty and keeps a 400x300 probe to a few thousand pixels.
    $sw = 64;
    $sh = max(1, (int) round($h * ($sw / $w)));
    $small = imagecreatetruecolor($sw, $sh);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
    imagedestroy($img);

    $sum = 0.0;
    $n   = 0;
    for ($y = 1; $y < $sh - 1; $y++) {
        for ($x = 1; $x < $sw - 1; $x++) {
            $c = imagecolorat($small, $x, $y);
            $lum = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);

            $l = 0.299 * ((imagecolorat($small, $x - 1, $y) >> 16) & 0xFF)
                + 0.587 * ((imagecolorat($small, $x - 1, $y) >> 8) & 0xFF)
                + 0.114 * (imagecolorat($small, $x - 1, $y) & 0xFF);
            $r = 0.299 * ((imagecolorat($small, $x + 1, $y) >> 16) & 0xFF)
                + 0.587 * ((imagecolorat($small, $x + 1, $y) >> 8) & 0xFF)
                + 0.114 * (imagecolorat($small, $x + 1, $y) & 0xFF);
            $u = 0.299 * ((imagecolorat($small, $x, $y - 1) >> 16) & 0xFF)
                + 0.587 * ((imagecolorat($small, $x, $y - 1) >> 8) & 0xFF)
                + 0.114 * (imagecolorat($small, $x, $y - 1) & 0xFF);
            $d = 0.299 * ((imagecolorat($small, $x, $y + 1) >> 16) & 0xFF)
                + 0.587 * ((imagecolorat($small, $x, $y + 1) >> 8) & 0xFF)
                + 0.114 * (imagecolorat($small, $x, $y + 1) & 0xFF);

            $sum += abs(4 * $lum - $l - $r - $u - $d);
            $n++;
        }
    }

    imagedestroy($small);

    return $n > 0 ? $sum / $n : 0.0;
}

/**
 * Capture the poster frame of a video: a fixed 1280x720 still that shows the
 * WHOLE frame (padded, never cropped), so a vertical video loses nothing of
 * its picture. The frame is chosen with the same sharpness ranking as
 * create_video_thumbnail(). The player crops the padding away at display time,
 * which keeps every poster the same shape without putting black bars around
 * the video. Returns false when nothing could be captured.
 */
function create_video_poster_frame(string $src, string $dest): bool
{
    return create_video_thumbnail($src, $dest, 1280, 720, 'pad');
}

/**
 * Capture the frame at an exact time offset (in seconds) from a video and
 * scale it to the given dimensions, saving as JPEG. Used by the admin frame
 * picker to choose any frame as the thumbnail.
 *
 * $fit decides what happens to a frame whose aspect differs from the box:
 *   'crop' (default) - fill the box and crop the overflow. Right for the
 *                      4:3 grid thumbnail, which must fill its tile.
 *   'pad'            - fit the whole frame inside the box and pad the
 *                      remainder with black. Right for the player poster:
 *                      nothing of the picture is lost, and the player crops
 *                      the padding away at display time.
 */
function create_video_frame(string $src, string $dest, int $width, int $height, int $second, int $timeout = 60, string $fit = 'crop'): bool
{
    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    if ($fit === 'pad') {
        // min(...,iw/ih) keeps a small source from being upscaled; the
        // decrease keeps the aspect; the pad centres the result.
        $filter = sprintf(
            "scale=w='min(%d,iw)':h='min(%d,ih)':force_original_aspect_ratio=decrease,"
            . 'pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black',
            $width,
            $height,
            $width,
            $height
        );
    } else {
        $filter = sprintf(
            'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
            $width, $height, $width, $height
        );
    }

    $second = max(0, $second);
    $time   = sprintf(
        '%02d:%02d:%02d',
        intdiv($second, 3600),
        intdiv($second % 3600, 60),
        $second % 60
    );

    // Bounded thumbnail generation: wrap ffmpeg in `timeout` and keep it off
    // stdin so a slow or pathological source (e.g. a large download with the
    // moov atom at the end, which forces an expensive full-file scan before
    // seeking) can never hang the upload request. The caller treats a missing
    // thumbnail as non-fatal, so this prevents the FPM request timeout from
    // marking the whole upload as "rejected by server".
    $cmd = 'timeout ' . max(5, $timeout) . ' ' . escapeshellarg($ffmpeg) . ' -nostdin -y -ss '
        . escapeshellarg($time)
        . ' -i ' . escapeshellarg($src)
        . ' -an -frames:v 1 -vf ' . escapeshellarg($filter)
        . ' -q:v 5 -f mjpeg ' . escapeshellarg($dest) . ' 2>&1';

    exec($cmd, $output, $status);

    return $status === 0 && is_file($dest);
}

/**
 * Render an admin empty state.
 *
 * "No galleries yet." on its own line is an apology, not a state. This gives
 * the operator an icon, a headline, one line of explanation and - where there
 * is an obvious next step - a button that performs it.
 *
 * @param string $icon    Icon name, e.g. 'image'.
 * @param string $title   Short headline, e.g. 'No categories yet'.
 * @param string $body    One sentence explaining what will appear here.
 * @param array{href?:string,label?:string}|null $action
 */
function admin_empty(string $icon, string $title, string $body = '', ?array $action = null): string
{
    $html = '<div class="adm-empty">'
        . '<span class="adm-empty-ico">' . \App\Core\Icon::svg($icon, 'nav-ico') . '</span>'
        . '<strong>' . e($title) . '</strong>';

    if ($body !== '') {
        $html .= '<p>' . e($body) . '</p>';
    }
    if ($action !== null && !empty($action['href']) && !empty($action['label'])) {
        $html .= '<a class="btn btn-sm" href="' . e((string) $action['href']) . '">'
            . e((string) $action['label']) . '</a>';
    }

    return $html . '</div>';
}

/**
 * Render the UTM source for a traffic-attributed signup.
 *
 * The Traffic page shows this in two tables (per-link and global), so the
 * "utm_source / utm_medium, or direct" formatting lives in one place.
 *
 * @param array $row a signup row with optional utm_source / utm_medium.
 */
function trafficSourceLabel(array $row): string
{
    $parts = array_filter(
        [$row['utm_source'] ?? null, $row['utm_medium'] ?? null],
        static fn ($v) => $v !== null && $v !== ''
    );

    return $parts === []
        ? '<span class="muted">direct</span>'
        : e(implode(' / ', $parts));
}
