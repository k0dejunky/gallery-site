<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\Database;
use App\Models\Photo;
use App\Models\VideoProject;

$jobId = (int) ($argv[1] ?? 0);
$job = Database::run(
    'SELECT j.*, p.source_photo_id, p.project_json FROM video_export_jobs j JOIN video_projects p ON p.id = j.project_id WHERE j.id = ? LIMIT 1',
    [$jobId]
)->fetch();
if (!$job) exit(1);

$metadata = json_decode((string) ($job['metadata_json'] ?? '{}'), true) ?: [];
$saveOverOriginal = !empty($metadata['save_over_original']);
$exportStart = max(0, (float) ($metadata['export_start'] ?? 0));
$exportEnd = max(0, (float) ($metadata['export_end'] ?? 0));
$exportW = max(0, (int) ($metadata['export_width'] ?? 0));
$exportH = max(0, (int) ($metadata['export_height'] ?? 0));

VideoProject::updateExport($jobId, 'running', 5);
$photo = Photo::find((int) $job['source_photo_id']);
$project = json_decode((string) $job['project_json'], true) ?: [];
$source = config('app.uploads')['dir'] . '/' . basename((string) ($photo['filename'] ?? ''));
$outputName = 'editor-export-' . $jobId . '-' . bin2hex(random_bytes(4)) . '.mp4';
if ($saveOverOriginal) {
    $output = config('app.uploads')['dir'] . '/' . $outputName;
} else {
    $exportsDir = config('app.uploads')['dir'] . '/exports';
    if (!is_dir($exportsDir)) @mkdir($exportsDir, 0775, true);
    $output = $exportsDir . '/' . $outputName;
}

if (!$photo || !is_file($source)) {
    VideoProject::updateExport($jobId, 'failed', 100, null, 'Source video not found.');
    exit(1);
}

$videoClip = null;
$audioClip = null;
foreach (($project['tracks'] ?? []) as $track) {
    if (($track['type'] ?? '') === 'video' && !empty($track['clips'][0])) $videoClip = $track['clips'][0];
    if (($track['type'] ?? '') === 'audio' && !empty($track['clips'][0])) $audioClip = $track['clips'][0];
}
$start = max(0, (float) ($videoClip['start'] ?? 0));
$end = max(0, (float) ($videoClip['end'] ?? 0));
if ($exportEnd > 0 && $exportEnd > $start) $end = $exportEnd;
if ($exportStart > $start) $start = $exportStart;
$duration = $end > $start ? $end - $start : null;

$speed = max(0.25, min(3, (float) ($project['speed'] ?? 1)));
$outDuration = $duration !== null ? $duration / $speed : null;

/**
 * Escape a string for use inside an FFmpeg drawtext text expression.
 */
function drawtext_escape(string $text): string
{
    return str_replace(['\\', "'", ':', '[', ']', '%'], ['\\\\', "\\'", '\\:', '\\[', '\\]', '\\%'], $text);
}

/**
 * Convert an absolute source timestamp to the export's output timeline
 * (post-trim, post-speed).
 */
function out_time(float $sourceTime, float $clipStart, float $speed): float
{
    return max(0, ($sourceTime - $clipStart) / $speed);
}

/**
 * Render a free-form brush blur mask (white stroke on black) with GD so ffmpeg
 * can blend a blurred copy of the frame through it with maskedmerge. Points are
 * normalized [x, y] coords; the brush radius is a fraction of the frame width.
 */
function build_brush_mask(string $file, int $w, int $h, array $points, float $r): void
{
    $im = imagecreatetruecolor($w, $h);
    $black = imagecolorallocate($im, 0, 0, 0);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, $w, $h, $black);
    $rx = max(1, (int) round($r * $w));
    $ry = max(1, (int) round($r * $h));
    foreach ($points as $pt) {
        $cx = (int) round(max(0, min(1, (float) ($pt[0] ?? 0))) * $w);
        $cy = (int) round(max(0, min(1, (float) ($pt[1] ?? 0))) * $h);
        imagefilledellipse($im, $cx, $cy, $rx * 2, $ry * 2, $white);
    }
    imagepng($im, $file);
    imagedestroy($im);
}

// Source geometry: the masks are drawn at the output frame size, which equals
// the source size (zoom crops back to the original dimensions).
$srcW = 1920;
$srcH = 1080;
$srcFps = 30;
$srcDuration = null;
$probeOut = [];
exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 ' . escapeshellarg($source) . ' 2>&1', $probeOut);
if (isset($probeOut[0]) && strpos($probeOut[0], ',') !== false) {
    $dims = array_map('intval', explode(',', $probeOut[0]));
    if (($dims[0] ?? 0) > 0 && ($dims[1] ?? 0) > 0) {
        $srcW = $dims[0];
        $srcH = $dims[1];
    }
}
$probeOut = [];
exec('ffprobe -v error -select_streams v:0 -show_entries stream=avg_frame_rate -of csv=p=0 ' . escapeshellarg($source) . ' 2>&1', $probeOut);
if (isset($probeOut[0]) && strpos($probeOut[0], '/') !== false) {
    [$fpsNum, $fpsDen] = array_map('trim', explode('/', $probeOut[0]));
    if ((int) $fpsDen > 0) $srcFps = round((float) $fpsNum / (float) $fpsDen, 3);
}
$probeOut = [];
exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($source) . ' 2>&1', $probeOut);
if (isset($probeOut[0]) && is_numeric($probeOut[0])) $srcDuration = (float) $probeOut[0];

$filters = [];

// Crop and mirror — scale to fill with zoom, pan, then mirror.
$crop = is_array($project['crop'] ?? null) ? $project['crop'] : [];
$zoom = max(1, min(3, (float) ($crop['zoom'] ?? 1)));
$panX = max(0, min(1, (float) ($crop['panX'] ?? 0.5)));
$panY = max(0, min(1, (float) ($crop['panY'] ?? 0.5)));
$mirrorH = !empty($crop['mirrorH']);
$mirrorV = !empty($crop['mirrorV']);

if (abs($zoom - 1) > 0.001) {
    // Scale up by zoom factor, crop back to original size at pan position.
    $zw = round($zoom, 2);
    $filters[] = 'scale=trunc(iw*' . $zw . '/2)*2:trunc(ih*' . $zw . '/2)*2';
    $filters[] = 'crop=iw/' . $zoom . ':ih/' . $zoom . ':trunc((iw-iw/' . $zoom . ')*' . $panX . '):trunc((ih-ih/' . $zoom . ')*' . $panY . ')';
}
if ($mirrorH) $filters[] = 'hflip';
if ($mirrorV) $filters[] = 'vflip';

// Speed change so all later timestamps use the output timeline.
if (abs($speed - 1) > 0.001) {
    $filters[] = 'setpts=PTS/' . $speed;
}

// Color/effect filters.
$fx = is_array($project['filters'] ?? null) ? $project['filters'] : [];
$eqParts = [];
$brightness = (float) ($fx['brightness'] ?? 0);
$contrast   = (float) ($fx['contrast'] ?? 1);
$saturation = (float) ($fx['saturation'] ?? 1);
if (abs($brightness) > 0.001) $eqParts[] = 'brightness=' . $brightness;
if (abs($contrast - 1) > 0.001) $eqParts[] = 'contrast=' . $contrast;
if (abs($saturation - 1) > 0.001) $eqParts[] = 'saturation=' . $saturation;
if ($eqParts) $filters[] = 'eq=' . implode(':', $eqParts);
if (!empty($fx['grayscale'])) $filters[] = 'hue=s=0';
if (!empty($fx['sepia'])) $filters[] = 'colorchannelmixer=.393:.769:.189:0:.349:.686:.168:0:.272:.534:.131';
if (!empty($fx['hue']) && abs((float) $fx['hue']) > 0.001) $filters[] = 'hue=h=' . (float) $fx['hue'];
if (!empty($fx['blur']) && (float) $fx['blur'] > 0) $filters[] = 'boxblur=' . min(8, (float) $fx['blur']);

// Blur regions: rectangle regions keep the crop/overlay path; free-form brush
// regions (points) get a GD mask blended over the whole-frame blur with
// maskedmerge so the blur follows the painted shape exactly.
$blurRegions = $project['blur_regions'] ?? [];
$maskInputs = [];
$tempMasks = [];
$hasMasks = false;
$prevLabel = '';
if (!empty($blurRegions)) {
    $baseFilters = $filters;
    $filters = [];
    $blurStrength = min(20, max(2, (int) ($blurRegions[0]['strength'] ?? 10)));
    $chain = implode(',', $baseFilters) . ',split=2[main][blur],' . '[blur]boxblur=' . $blurStrength . ':' . $blurStrength . '[blurred]';
    $prevLabel = 'main';
    $maskInputNo = 1;
    $hasBrush = false;
    $maskDur = $outDuration !== null ? $outDuration : ($srcDuration ?? 3600);
    $idx = 0;
    foreach ($blurRegions as $br) {
        $x = max(0, min(1, (float) ($br['x'] ?? 0)));
        $y = max(0, min(1, (float) ($br['y'] ?? 0)));
        $w = max(0.01, min(1, (float) ($br['w'] ?? 0.1)));
        $h = max(0.01, min(1, (float) ($br['h'] ?? 0.1)));
        $brStart = out_time(max(0, (float) ($br['start'] ?? 0)), $start, $speed);
        $brEnd = out_time(max(0, (float) ($br['end'] ?? 5)), $start, $speed);
        $enable = "enable='between(t," . $brStart . "," . $brEnd . ")'";
        $points = $br['points'] ?? null;
        if (is_array($points) && count($points) >= 2) {
            $r = max(0.005, min(0.5, (float) ($br['r'] ?? 0.05)));
            $maskFile = sys_get_temp_dir() . '/ve-brush-' . $jobId . '-' . $idx . '.png';
            build_brush_mask($maskFile, $srcW, $srcH, $points, $r);
            $tempMasks[] = $maskFile;
            $maskInputs[] = '-loop 1 -framerate ' . $srcFps . ' -i ' . escapeshellarg($maskFile);
            $chain .= ',[' . $maskInputNo . ':v]trim=duration=' . $maskDur . ',setpts=PTS-STARTPTS[mt' . $idx . ']';
            $chain .= ',[' . $prevLabel . '][blurred][mt' . $idx . ']maskedmerge=' . $enable . '[mrg' . $idx . ']';
            $prevLabel = 'mrg' . $idx;
            $maskInputNo++;
            $hasBrush = true;
        } else {
            // Crop the blurred stream to just this region, scale it back up, overlay on base.
            $chain .= ',[' . $prevLabel . ']split=2[base' . $idx . '][next' . $idx . ']';
            $chain .= ',[blurred]crop=iw*' . $w . ':ih*' . $h . ':iw*' . $x . ':ih*' . $y . ',scale=iw/' . $w . ':ih/' . $h . '[crop' . $idx . ']';
            $chain .= ',[base' . $idx . '][crop' . $idx . ']overlay=x=W*' . $x . ':y=H*' . $y . $enable . '[merged' . $idx . ']';
            $prevLabel = 'merged' . $idx;
        }
        $idx++;
    }
    $hasMasks = count($maskInputs) > 0;
    if ($hasBrush) $chain = 'setpts=PTS-STARTPTS,' . $chain;
    $filters = [$chain];
}

// Text overlays with per-item color/size/position/opacity/padding/shadow, on the output timeline.
foreach (($project['text_overlays'] ?? []) as $item) {
    $text = drawtext_escape((string) ($item['text'] ?? ''));
    if ($text === '') continue;
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($item['color'] ?? '')) ? '0x' . substr($item['color'], 1) : 'white';
    $opacity = max(0, min(1, (float) ($item['opacity'] ?? 1)));
    $alpha = $opacity < 1 ? '@' . $opacity : '';
    $padding = max(0, min(40, (int) ($item['padding'] ?? 8)));
    $shadow = !empty($item['shadow']);
    $shadowStr = $shadow ? ':shadowcolor=black@0.8:shadowx=2:shadowy=2' : '';
    $st = out_time((float) ($item['start'] ?? 0), $start, $speed);
    $en = max($st + .1, out_time((float) ($item['end'] ?? 5), $start, $speed));
    $filters[] = "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:text='" . $text . "':fontcolor=" . $color . $alpha . ":fontsize=" . (int) ($item['font_size'] ?? 32) . ":x=W*" . (float) ($item['x'] ?? .5) . "-text_w/2:y=H*" . (float) ($item['y'] ?? .85) . "-text_h/2:box=1:boxcolor=black@0.45:boxborderw=" . $padding . $shadowStr . ":enable='between(t," . $st . "," . $en . ")'";
}

// Captions, bottom-centered, on the output timeline.
foreach (($project['tracks'] ?? []) as $track) {
    if (($track['type'] ?? '') !== 'captions') continue;
    foreach (($track['items'] ?? []) as $item) {
        $text = drawtext_escape((string) ($item['text'] ?? ''));
        if ($text === '') continue;
        $opacity = max(0, min(1, (float) ($item['opacity'] ?? 1)));
        $alpha = $opacity < 1 ? '@' . $opacity : '';
        $padding = max(0, min(40, (int) ($item['padding'] ?? 8)));
        $shadow = !empty($item['shadow']);
        $shadowStr = $shadow ? ':shadowcolor=black@0.8:shadowx=2:shadowy=2' : '';
        $st = out_time((float) ($item['start'] ?? 0), $start, $speed);
        $en = max($st + .1, out_time((float) ($item['end'] ?? 5), $start, $speed));
        $filters[] = "drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf:text='" . $text . "':fontcolor=white" . $alpha . ":fontsize=28:x=(w-text_w)/2:y=h-text_h-40:box=1:boxcolor=black@0.6:boxborderw=" . $padding . $shadowStr . ":enable='between(t," . $st . "," . $en . ")'";
    }
}

// Fade in/out transition on the output timeline.
if (($videoClip['transition'] ?? 'none') === 'fade' && $outDuration !== null && $outDuration > 1.2) {
    $filters[] = 'fade=t=in:st=0:d=0.5';
    $filters[] = 'fade=t=out:st=' . max(0, $outDuration - 0.5) . ':d=0.5';
}

// Optional export resolution override (masks are drawn at source size and the
// final frame is downscaled, so region coordinates stay consistent).
if ($exportW > 0 && $exportH > 0 && ($exportW !== $srcW || $exportH !== $srcH)) {
    $filters[] = 'scale=' . $exportW . ':' . $exportH;
}

$filters[] = 'format=yuv420p';

// Audio filter chain: speed, volume/mute, fades.
$audioFilters = [];
if (abs($speed - 1) > 0.001) {
    $remaining = $speed;
    while ($remaining > 2.0 + 0.001) { $audioFilters[] = 'atempo=2.0'; $remaining /= 2.0; }
    while ($remaining < 0.5 - 0.001) { $audioFilters[] = 'atempo=0.5'; $remaining /= 0.5; }
    $audioFilters[] = 'atempo=' . $remaining;
}
$volume = max(0, min(2, (float) ($audioClip['volume'] ?? 1)));
if (!empty($audioClip['muted'])) $volume = 0;
if (abs($volume - 1) > 0.001) $audioFilters[] = 'volume=' . $volume;
$fadeIn = max(0, (float) ($audioClip['fade_in'] ?? 0));
$fadeOut = max(0, (float) ($audioClip['fade_out'] ?? 0));
if ($fadeIn > 0) $audioFilters[] = 'afade=t=in:st=0:d=' . min(10, $fadeIn);
if ($fadeOut > 0 && $outDuration !== null) $audioFilters[] = 'afade=t=out:st=' . max(0, $outDuration - min(10, $fadeOut)) . ':d=' . min(10, $fadeOut);

$command = 'ffmpeg -y -hide_banner -loglevel error';
if ($start > 0) $command .= ' -ss ' . escapeshellarg((string) $start);
if ($duration !== null) $command .= ' -t ' . escapeshellarg((string) $duration);
$command .= ' -i ' . escapeshellarg($source);
foreach ($maskInputs as $maskInput) $command .= ' ' . $maskInput;
if ($hasMasks) {
    // The blur chain's last filter emits a labeled output ([mrgN]/[mergedN]);
    // subsequent filters must reference that label explicitly, and the final
    // filter's output is labeled [vevout] so -map can select it.
    $tail = array_slice($filters, 1);
    if ($tail) {
        $tail[count($tail) - 1] .= '[vevout]';
        $filters = [$filters[0] . ',[' . $prevLabel . ']' . implode(',', $tail)];
        $mapVideo = '-map "[vevout]"';
    } else {
        $mapVideo = '-map "[' . $prevLabel . ']"';
    }
    $command .= ' -filter_complex ' . escapeshellarg(implode(',', $filters));
    $command .= ' ' . $mapVideo . ' -map 0:a?';
} else {
    $command .= ' -vf ' . escapeshellarg(implode(',', $filters));
    $command .= ' -map 0:v:0 -map 0:a?';
}
if ($audioFilters) $command .= ' -af ' . escapeshellarg(implode(',', $audioFilters));
$command .= ' -c:v libx264 -preset veryfast -crf 23 -c:a aac -movflags +faststart';
if ($outDuration !== null) $command .= ' -t ' . escapeshellarg((string) $outDuration);
if ($hasMasks) $command .= ' -shortest';
$command .= ' ' . escapeshellarg($output);

$lines = [];
$status = 0;
exec($command . ' 2>&1', $lines, $status);
foreach ($tempMasks as $tempMask) if (is_file($tempMask)) @unlink($tempMask);
if ($status !== 0 || !is_file($output)) {
    VideoProject::updateExport($jobId, 'failed', 100, null, substr(implode("\n", $lines), 0, 2000) ?: 'FFmpeg export failed.');
    exit(1);
}

VideoProject::updateExport($jobId, 'completed', 100, $outputName, null);

$thumbConfig = config('app.uploads');
$thumbDest = $thumbConfig['dir'] . '/thumb_' . $outputName;
create_video_thumbnail($output, $thumbDest, $thumbConfig['thumb_width'], $thumbConfig['thumb_height']);
