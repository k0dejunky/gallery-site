<?php

declare(strict_types=1);

/**
 * Video chapters worker: runs ffmpeg scene detection over a video and stores
 * the detected scene boundaries as chapter markers, which the player renders as
 * seek-bar ticks plus a clickable chapter list.
 *
 * DB-driven (one row per video photo, not one per file on disk) because a
 * chapter list only has meaning for media the site actually serves. A video is
 * analysed when it has no chapters yet, or when its chapters predate the source
 * file (so an in-place re-encode re-analyses). Videos shorter than
 * VideoChapter::MIN_DURATION are skipped: a scene list on a 40s clip is noise.
 *
 * Chapter TITLES are placeholders ("Start", "Part N") unless
 * app.video_chapters.ai_titles is on, in which case up to three sampled frames
 * are sent to the site's configured AI vision backend (the same one the
 * categorizer/describer use) for short labels. Off by default because every
 * title costs a model call.
 *
 * Runs from /etc/cron.d ("--once"); a lock file prevents overlapping ticks.
 *
 * Usage:
 *   php bin/video_chapters_worker.php --once [--limit=N] [--photos=1,2,3]
 */

require __DIR__ . '/../app/bootstrap.php';

// The liveness heartbeat: a worker that only logs when it has work
// would look dead to the watchdog if it were watched by log mtime.
\App\Core\Heartbeat::touch('chapters');

use App\Core\CategoryAdvisor;
use App\Models\VideoChapter;

$limit  = 0;
$photos = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--photos=([\d,]+)$/', $arg, $m)) {
        $photos = array_values(array_filter(array_map('intval', explode(',', $m[1]))));
    }
}

$logDir   = dirname(__DIR__) . '/storage/logs';
$lockFile = $logDir . '/chapters.lock';
$logFile  = $logDir . '/chapters.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lock = fopen($lockFile, 'c');
if ($lock === false) {
    error_log('[chapters] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

if ((bool) config('app.video_chapters.enabled', true) !== true) {
    $log('chapters disabled in config, nothing to do');
    exit(0);
}

$uploads = rtrim((string) config('app.uploads.dir'), '/');
$threshold = (float) config('app.video_chapters.scene_threshold', 0.35);
$minGap    = (float) config('app.video_chapters.min_gap_seconds', 10.0);
$maxChap   = (int) config('app.video_chapters.max_chapters', VideoChapter::MAX_CHAPTERS);
$aiTitles  = (bool) config('app.video_chapters.ai_titles', false);
$driver    = (string) env_value('CATEGORIZER_DRIVER', 'ollama');

/**
 * Scene-boundary timestamps from ffmpeg's scene filter. `select` drops every
 * frame that is not a cut, so showinfo only logs the ones we want; each log
 * line carries the frame's pts_time.
 */
$sceneTimes = static function (string $src) use ($threshold): array {
    $ffmpeg = is_executable('/usr/bin/ffmpeg') ? '/usr/bin/ffmpeg' : 'ffmpeg';
    $filter = "select='gt(scene," . rtrim(rtrim(number_format($threshold, 4, '.', ''), '0'), '.') . ")',showinfo";

    // Whole-clip decode with no audio, bounded so a pathological source cannot
    // wedge a cron slot forever. Detection is the only way to find real cuts,
    // so there is no cheap sampling here.
    $cmd = 'timeout 900 ' . escapeshellarg($ffmpeg) . ' -nostdin -i ' . escapeshellarg($src)
        . ' -an -sn -vf ' . escapeshellarg($filter) . ' -f null - 2>&1';

    $output = [];
    @exec($cmd, $output, $rc);

    if ($rc !== 0) {
        return null; // ffmpeg failed / timed out
    }

    $times = [];
    foreach ($output as $line) {
        if (!str_contains($line, 'showinfo') || !str_contains($line, 'pts_time:')) {
            continue;
        }
        if (preg_match('/pts_time:([0-9]+(?:\.[0-9]+)?)/', $line, $m)) {
            $times[] = (float) $m[1];
        }
    }

    return $times;
};

/** Ask the AI backend for short chapter titles; returns titles or null. */
$askTitles = static function (string $src, float $duration, array $chapters) use ($uploads, $aiTitles, $driver, $log): ?array {
    if (!$aiTitles || count($chapters) < 2 || ($driver !== 'ollama' && $driver !== 'api')) {
        return null;
    }

    // Three frames are enough context for a human-like summary and keep the
    // call cheap; frame i represents the midpoint of chapter i.
    $images = [];
    $step   = (int) floor(count($chapters) / 3);
    $picks  = $step > 0 ? [0, $step, 2 * $step] : [0];
    foreach ($picks as $i) {
        $at = (int) ($chapters[$i]['start'] + 5);
        $tmp = sys_get_temp_dir() . '/chapter_' . bin2hex(random_bytes(6)) . '.jpg';
        if (create_video_frame($src, $tmp, 640, 360, $at) && is_file($tmp)) {
            $data = @file_get_contents($tmp);
            if (is_string($data) && $data !== '') {
                $images[] = base64_encode($data);
            }
        }
        @unlink($tmp);
    }

    if ($images === []) {
        return null;
    }

    $prompt = "These are frames sampled from a " . (int) round($duration)
        . " second video, in order. Give one short title (2-5 words, no punctuation at the end) for EACH of the "
        . count($chapters) . " chapters of this video, as a JSON array of strings in the same order.\n"
        . "Example: [\"Opening\", \"Main set\", \"Closing\"]";

    try {
        $raw = $driver === 'ollama'
            ? CategoryAdvisor::callOllama($prompt, $images)
            : CategoryAdvisor::callApi($prompt, $images);
    } catch (\Throwable $e) {
        $log('title call failed: ' . $e->getMessage());
        return null;
    }

    if (preg_match('/\[.*\]/s', $raw, $m)) {
        $decoded = json_decode($m[0], true);
    } else {
        $decoded = json_decode($raw, true);
    }

    if (!is_array($decoded)) {
        return null;
    }

    $titles = [];
    foreach ($decoded as $t) {
        if (!is_string($t)) {
            continue;
        }
        $t = trim(preg_replace('/^[\s"\']+|[\s"\']+$/u', '', $t) ?? '');
        if ($t !== '') {
            $titles[] = mb_substr($t, 0, 60);
        }
    }

    // Only trust a full-length list; a partial answer would mislabel chapters.
    return count($titles) === count($chapters) ? $titles : null;
};

$where = 'p.is_video = 1';
$params = [];
if ($photos !== []) {
    $where .= ' AND p.id IN (' . implode(',', array_map('intval', $photos)) . ')';
} else {
    $where .= ' ORDER BY p.id ASC';
    if ($limit > 0) {
        $where .= ' LIMIT ' . (int) $limit;
    }
}

$rows = \App\Core\Database::run(
    'SELECT p.id, p.filename, p.duration_seconds FROM photos p WHERE ' . $where,
    $params
)->fetchAll();

$analysed = 0;
$skipped  = 0;
$failed   = 0;
$capped   = $limit > 0 && count($rows) >= $limit && $photos === [] ? $limit : 0;

foreach ($rows as $row) {
    if ($capped > 0 && $analysed >= $capped) {
        break;
    }

    $photoId  = (int) $row['id'];
    $filename = (string) $row['filename'];
    $src      = $uploads . '/' . $filename;

    if (!is_file($src)) {
        $skipped++;
        continue;
    }

    if (!VideoChapter::needsAnalysis($photoId, (int) @filemtime($src))) {
        $skipped++;
        continue;
    }

    $duration = (int) ($row['duration_seconds'] ?? 0);
    if ($duration <= 0) {
        $duration = video_duration_seconds($src);
    }

    if ($duration < VideoChapter::MIN_DURATION) {
        // Too short for a chapter list. Record nothing (re-checking is a cheap
        // stat) rather than storing a single marker.
        $skipped++;
        continue;
    }

    $times = $sceneTimes($src);
    if ($times === null) {
        $failed++;
        $log('scene detection failed for photo ' . $photoId . ' (' . $filename . ')');
        continue;
    }

    $chapters = VideoChapter::parseSceneTimes($times, (float) $duration, $minGap, $maxChap);
    if ($chapters === []) {
        $skipped++;
        continue;
    }

    $titles = $askTitles($src, (float) $duration, $chapters);
    if ($titles !== null) {
        foreach ($chapters as $i => $c) {
            $chapters[$i]['title'] = $titles[$i];
        }
    }

    $stored = VideoChapter::replaceForPhoto($photoId, $chapters);
    $analysed++;
    $log('photo ' . $photoId . ' ' . $filename . ': ' . $stored . ' chapters from ' . count($times)
        . ' scene cuts' . ($titles !== null ? ' (AI titles)' : ''));
}

$log('tick done: analysed=' . $analysed . ' skipped=' . $skipped . ' failed=' . $failed
    . ' candidates=' . count($rows));

exit(0);