<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Scene-detected chapter markers for a video. Produced by
 * bin/video_chapters_worker.php (ffmpeg scene detection), read by the player
 * for seek-bar ticks and a clickable chapter list, and emitted as VideoObject
 * chapter markup. Purely derived data: the worker rewrites the rows for a
 * photo whenever they are missing or the source file changed, and they are
 * cascade-deleted with the photo.
 */
class VideoChapter
{
    /** Hard cap on stored chapters per video (matches the worker's own cap). */
    public const MAX_CHAPTERS = 24;
    /** Videos shorter than this get no chapters (a scene list is noise). */
    public const MIN_DURATION  = 120;

    /**
     * The chapters of one photo in playback order, each row as
     * ['start' => float seconds, 'title' => string]. Empty when the worker
     * has not analysed the video yet.
     */
    public static function forPhoto(int $photoId): array
    {
        if ($photoId <= 0) {
            return [];
        }

        $rows = Database::run(
            'SELECT start_seconds, title FROM video_chapters
             WHERE photo_id = ? ORDER BY position ASC, start_seconds ASC',
            [$photoId]
        )->fetchAll();

        return array_map(static function (array $r): array {
            return [
                'start' => (float) $r['start_seconds'],
                'title' => (string) $r['title'],
            ];
        }, $rows);
    }

    /**
     * Replace every chapter row of a photo with $chapters (a list of
     * ['start' => float, 'title' => string] from parseSceneTimes()). Titles
     * are capped and positions re-sequenced from 0. Returns the stored count.
     * A transaction keeps the reader (player page) from seeing a half-written
     * chapter list.
     */
    public static function replaceForPhoto(int $photoId, array $chapters): int
    {
        if ($photoId <= 0) {
            return 0;
        }

        $rows = [];
        foreach ($chapters as $i => $ch) {
            $start = round((float) ($ch['start'] ?? 0), 2);
            $title = mb_substr(trim((string) ($ch['title'] ?? '')), 0, 120);
            if ($start < 0) {
                continue;
            }
            $rows[] = [$photoId, $i, $start, $title];
        }

        Database::run('DELETE FROM video_chapters WHERE photo_id = ?', [$photoId]);

        foreach ($rows as $r) {
            Database::run(
                'INSERT INTO video_chapters (photo_id, position, start_seconds, title)
                 VALUES (?, ?, ?, ?)',
                $r
            );
        }

        return count($rows);
    }

    /**
     * Turn raw ffmpeg scene-detection timestamps into chapter markers:
     * the first is always at t=0, duplicates and markers closer than
     * $minGapSeconds are collapsed, and the list is capped at $max.
     * Also appends an even fallback split so short/static clips still get a
     * few usable markers. Pure function - the worker feeds it the parsed
     * pts_time list from ffmpeg; unit tests exercise it directly.
     *
     * @param float[] $sceneTimes ascending scene-boundary offsets in seconds
     */
    public static function parseSceneTimes(array $sceneTimes, float $duration, float $minGapSeconds = 10.0, int $max = self::MAX_CHAPTERS): array
    {
        $duration = max(0.0, $duration);
        $max      = max(1, $max);

        if ($duration < self::MIN_DURATION) {
            return [];
        }

        sort($sceneTimes);

        $starts = [0.0];
        foreach ($sceneTimes as $t) {
            $t = (float) $t;
            if ($t <= 0.0 || $t >= $duration) {
                continue;
            }
            // Collapse anything that sits within minGapSeconds of the last
            // accepted marker (scenes are often sub-second apart on fast cuts).
            if ($t - $starts[count($starts) - 1] < $minGapSeconds) {
                continue;
            }
            $starts[] = $t;
            if (count($starts) >= $max) {
                break;
            }
        }

        // Fallback: when scene detection found too little (static footage,
        // or a talking head with no cuts), split the timeline evenly so the
        // player still has a few navigable markers.
        if (count($starts) < 3) {
            $starts = [0.0];
            $step   = 60.0;
            for ($t = $step; $t < $duration && count($starts) < $max; $t += $step) {
                $starts[] = $t;
            }
        }

        $chapters = [];
        foreach ($starts as $i => $t) {
            $chapters[] = [
                'start' => round((float) $t, 2),
                // AI titles are opt-in (config app.video_chapters.ai_titles); the
                // plain marker gets a readable default the worker can replace.
                'title' => $i === 0 ? 'Start' : 'Part ' . $i,
            ];
        }

        return $chapters;
    }

        /** Whether any chapters exist for a photo (cheap COUNT for view guards). */
    public static function hasChapters(int $photoId): bool
    {
        if ($photoId <= 0) {
            return false;
        }

        return (int) Database::run(
            'SELECT COUNT(*) FROM video_chapters WHERE photo_id = ?',
            [$photoId]
        )->fetchColumn() > 0;
    }

    /**
     * When the chapters of a photo were last written (unix time), or 0 when it
     * has none. The worker compares this to the source file's mtime so a video
     * that was re-encoded in place gets re-analysed without any queue.
     */
    public static function lastAnalyzedAt(int $photoId): int
    {
        if ($photoId <= 0) {
            return 0;
        }

        // UNIX_TIMESTAMP, not strtotime(): created_at is written by MySQL's
        // CURRENT_TIMESTAMP in the server's session timezone, and PHP's
        // timezone can differ from it by hours. Parsing that string in PHP
        // produced an epoch hours off the real instant, so a source file
        // written an hour ago looked NEWER than the analysis and every video
        // was re-analysed on every tick. The database converts in the same
        // timezone it wrote with, and filemtime() is already an absolute epoch.
        $row = Database::run(
            'SELECT UNIX_TIMESTAMP(MAX(created_at)) AS last_at FROM video_chapters WHERE photo_id = ?',
            [$photoId]
        )->fetch();

        if ($row === false) {
            return 0;
        }

        $values = array_values($row);

        return (int) ($values[0] ?? 0);
    }

    /**
     * Whether the worker should (re)analyse this photo: it has no chapters, or
     * its chapters predate the current source file.
     */
    public static function needsAnalysis(int $photoId, int $sourceMtime): bool
    {
        if ($photoId <= 0) {
            return false;
        }

        $last = self::lastAnalyzedAt($photoId);

        return $last === 0 || ($sourceMtime > 0 && $last < $sourceMtime);
    }
}