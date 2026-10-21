<?php

declare(strict_types=1);

namespace App\Core;

/**
 * A worker's liveness heartbeat.
 *
 * A worker that only writes to its log when it has something to do cannot be
 * watched by the log's mtime: an idle worker looks exactly like a dead one.
 * The auto-poster was the only worker with a heartbeat, and the watchdog
 * built in phase 1 immediately reported the emailer, the auto-poster, the
 * daily chat and the questionnaire sender as "stale" on a box where they
 * were working perfectly - they simply had nothing to do.
 *
 * So every worker touches a heartbeat file on EVERY run, and the watchdog
 * reads the heartbeats. A worker that has not touched its heartbeat is
 * genuinely not running.
 */
final class Heartbeat
{
    /** The directory the heartbeat files live in. */
    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }

    /**
     * Record that a worker has just run.
     */
    public static function touch(string $worker): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @touch($dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $worker) . '.heartbeat');
    }

    /**
     * How many seconds ago a worker last ran. 0 means it never has.
     */
    public static function age(string $worker): int
    {
        $path = self::dir() . '/' . preg_replace('/[^a-z0-9_-]/i', '', $worker) . '.heartbeat';
        if (!is_file($path)) {
            return 0;
        }
        return max(0, time() - (int) @filemtime($path));
    }
}
