<?php

declare(strict_types=1);

/**
 * Video ladder worker: builds the bitrate rungs (config app.video_ladder) and
 * the wide poster frame for every video on disk that needs them.
 *
 * It is filesystem-driven rather than queue-driven on purpose: renditions are
 * derived data, so a missing rung is discovered by stat'ing the uploads dir
 * instead of by a row someone remembered to enqueue. A rung is (re)built when
 * it is missing OR older than its source file, which is what makes a re-encode
 * of the original (e.g. the watermark helper rewriting it in place) propagate
 * to every rung without a queue entry.
 *
 * Mid-rung (720p) rungs are included: uploads build it synchronously, but
 * every video uploaded before renditions existed (or whose synchronous build
 * failed) is missing it, and that file is what the player falls back to.
 *
 * Runs from /etc/cron.d ("--once"); a lock file prevents overlapping ticks and
 * the shared /usr/bin/ffmpeg guard caps concurrent encodes site-wide. The
 * worker stands down entirely while an AI vision worker holds its lock, since
 * both are CPU-bound and the model calls are latency-critical.
 *
 * Usage:
 *   php bin/video_rendition_worker.php --once [--limit=N] [--budget=SECONDS]
 *   php bin/video_rendition_worker.php --once --limit=0    (drain everything)
 */

require __DIR__ . '/../app/bootstrap.php';

$limit  = null; // null = not given yet; 0 = drain the whole backlog
$budget = 900;  // seconds per tick; stops mid-backfill to leave the box responsive

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--budget=(\d+)$/', $arg, $m)) {
        $budget = max(60, (int) $m[1]);
    }
}

// Default 3 rungs per tick (each is a full re-encode, so the tick is normally
// minutes long, not seconds). An explicit --limit=0 means "no limit" and must
// not be collapsed into the default - that silently capped the first manual
// backfill run at 3.
$limit = $limit === null ? 3 : $limit;

/** Hard cap on one encode. A 7-minute 4K source can take half an hour on a
 *  busy box; that is fine occasionally but it must not hold a cron slot and an
 *  ffmpeg guard permit indefinitely. */
const ENCODE_TIMEOUT = 2400;

$logDir  = dirname(__DIR__) . '/storage/logs';
$lockFile = $logDir . '/rendition.lock';
$logFile  = $logDir . '/rendition.log';

if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lock = fopen($lockFile, 'c');
if ($lock === false) {
    error_log('[renditions] unable to open lock file ' . $lockFile);
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // another tick is running
}

$log = static function (string $line) use ($logFile): void {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
};

/**
 * Locks held by the AI vision workers. Both spend minutes inside a model call
 * on the same box; transcoding alongside them starves the model and the site.
 */
$aiLocks = ['describer.lock', 'categorizer.lock'];
foreach ($aiLocks as $aiLock) {
    $fh = @fopen($logDir . '/' . $aiLock, 'c');
    if ($fh !== false) {
        $busy = !flock($fh, LOCK_EX | LOCK_NB);
        @fclose($fh);
        if ($busy) {
            $log('AI worker busy (' . $aiLock . '); skipping this tick');
            exit(0);
        }
    }
}

$uploads = rtrim((string) config('app.uploads.dir'), '/');
$videoExt = (array) config('app.uploads.video_ext', []);
$ladder   = video_ladder();

if ($ladder === []) {
    $log('no video_ladder configured, nothing to do');
    exit(0);
}

/** Probe a video's pixel dimensions (null when unreadable). */
$dimensions = static function (string $path): ?array {
    $ffprobe = is_executable('/usr/bin/ffprobe') ? '/usr/bin/ffprobe' : 'ffprobe';
    $probe   = [];
    $rc      = 0;
    @exec(
        escapeshellarg($ffprobe) . ' -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 '
        . escapeshellarg($path) . ' 2>/dev/null',
        $probe,
        $rc
    );
    if ($rc === 0 && isset($probe[0])) {
        $parts = array_map('intval', explode(',', (string) $probe[0]));
        if (count($parts) >= 2 && $parts[0] > 0 && $parts[1] > 0) {
            return [$parts[0], $parts[1]];
        }
    }

    return null;
};

/** Source files whose rungs may be missing, oldest uploads first. */
$collectCandidates = static function () use ($uploads, $videoExt, $ladder): array {
    // Derived files live in the same directory under their variant prefixes.
    // Without this filter the worker happily re-encodes web_<x>.mp4 into
    // weblo_web_<x>.mp4, forever, doubling the variant count every pass.
    $prefixes = array_map(static fn ($r) => (string) $r['prefix'], $ladder);
    $prefixes[] = 'poster_';
    $prefixes[] = 'thumb_';
    $prefixes[] = 'blur_thumb_';

    $out = [];
    foreach ([$uploads, $uploads . '/exports'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $entries = @scandir($dir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, $videoExt, true)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    continue 2;
                }
            }
            // Editor temp files (faststart .tmp, partial worker encodes).
            if (str_contains($entry, '.tmp') || str_contains($entry, '.part')) {
                continue;
            }
            $out[$path] = (int) @filemtime($path);
        }
    }

    // PRIORITY: a video with no mid (720p) rendition is what makes a member
    // stream the multi-hundred-MB ORIGINAL, so it is a broken player, not a
    // nicety. Those go first regardless of age; the extra lo/hi rungs (a
    // bandwidth nicety) follow, oldest first, so new uploads - which already
    // have their synchronous mid rung - never starve the real backlog.
    $withoutMid = [];
    $rest       = [];
    foreach ($out as $path => $mtime) {
        $hasMid = false;
        foreach ($ladder as $rung) {
            if ($rung['key'] === 'mid' && is_file(dirname($path) . '/' . $rung['prefix'] . basename($path))) {
                $hasMid = true;
                break;
            }
        }
        if ($hasMid) {
            $rest[$path] = $mtime;
        } else {
            $withoutMid[$path] = $mtime;
        }
    }
    asort($withoutMid);
    asort($rest);

    return $withoutMid + $rest;
};

/**
 * The rungs to build for one source, mid first (it is the player's fallback),
 * then ascending resolution, so a tick always improves what plays by default.
 */
$orderPlan = static function (array $plan) use ($ladder): array {
    $order = array_flip(array_column($ladder, 'key'));
    usort($plan, static function ($a, $b) use ($order) {
        $aMid = $a['key'] === 'mid' ? 0 : 1;
        $bMid = $b['key'] === 'mid' ? 0 : 1;
        if ($aMid !== $bMid) {
            return $aMid <=> $bMid;
        }

        return ($order[$a['key']] ?? 0) <=> ($order[$b['key']] ?? 0);
    });

    return $plan;
};

$candidates = $collectCandidates();
$built     = 0;
$skipped   = 0;
$failed    = 0;
$started   = time();
$done      = false;

foreach ($candidates as $src => $srcMtime) {
    if ($done || ($limit > 0 && $built >= $limit)) {
        break;
    }
    if ((time() - $started) >= $budget) {
        $log('time budget reached, leaving the rest for the next tick');
        break;
    }

    $filename = basename($src);
    $dims     = $dimensions($src);
    if ($dims === null) {
        $skipped++;
        continue;
    }

    [$srcW, $srcH] = $dims;

    // --- poster frame ---------------------------------------------------
    $poster = $uploads . '/poster_' . $filename;
    if (!is_file($poster) || (int) @filemtime($poster) < $srcMtime) {
        if (create_video_poster_frame($src, $poster)) {
            @chmod($poster, 0644);
            $log('poster ' . $filename . ' ' . $srcW . 'x' . $srcH);
            $built++;
        } else {
            @unlink($poster);
            $failed++;
        }
        if (($limit > 0 && $built >= $limit) || (time() - $started) >= $budget) {
            $done = true;
            break;
        }
    }

    // --- ladder rungs ----------------------------------------------------
    foreach ($orderPlan(video_ladder_plan($srcW, $srcH)) as $rung) {
        $dest = $uploads . '/' . $rung['prefix'] . $filename;

        if (is_file($dest) && (int) @filemtime($dest) >= $srcMtime) {
            continue; // current
        }

        // Encode to a temp name and move into place, so a killed worker never
        // leaves a half-written file that filemtime would call "current".
        $tmp = $dest . '.part' . getmypid();
        $ok  = create_video_rendition($src, $tmp, $rung, ENCODE_TIMEOUT);
        if ($ok && is_file($tmp)) {
            @chmod($tmp, 0644);
            if (@rename($tmp, $dest)) {
                clearstatcache(true, $dest);
                $log($rung['key'] . ' ' . $rung['label'] . ' ' . $filename
                    . ' ' . $srcW . 'x' . $srcH . ' ' . (int) filesize($dest) . ' bytes');
                $built++;
            } else {
                @unlink($tmp);
                $failed++;
            }
        } else {
            @unlink($tmp);
            $failed++;
            $log('FAILED ' . $rung['key'] . ' ' . $filename);
        }

        if (($limit > 0 && $built >= $limit) || (time() - $started) >= $budget) {
            $done = true;
            break;
        }
    }
}

$log('tick done: built=' . $built . ' failed=' . $failed . ' skipped=' . $skipped
    . ' candidates=' . count($candidates) . ' seconds=' . (time() - $started));

exit(0);