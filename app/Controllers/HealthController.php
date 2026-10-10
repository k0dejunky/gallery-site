<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

/** Public, deliberately small liveness/readiness response for monitoring. */
class HealthController extends Controller
{
    public function show(): void
    {
        $db = true;
        try {
            Database::run('SELECT 1')->fetchColumn();
        } catch (\Throwable $error) {
            $db = false;
        }

        $uploads = (string) config('app.uploads.dir');
        $directories = [dirname(__DIR__, 2) . '/storage', $uploads, $uploads . '/pending', $uploads . '/exports'];
        $storage = count(array_filter($directories, static function (string $directory): bool {
            return is_dir($directory) && is_readable($directory) && is_writable($directory);
        })) === count($directories);

        // Core readiness stays DB + storage. The rest is surfaced as data so
        // an external monitor can alert on it without taking the site down.
        $ok = $db && $storage;

        $backupDir = dirname(__DIR__, 2) . '/storage/backups';
        $lastOk  = is_file($backupDir . '/.last_ok') ? @filemtime($backupDir . '/.last_ok') : 0;
        $running = is_file($backupDir . '/.running');

        $redis = false;
        try {
            $redis = \App\Core\Cache::available();
        } catch (\Throwable $error) {
            $redis = false;
        }

        // Housekeeping runs every few minutes; the cron log mtime is a cheap
        // proxy for "is the scheduled job actually firing".
        $cronLog = dirname(__DIR__, 2) . '/storage/logs/cron.log';
        $lastHousekeeping = is_file($cronLog) ? @filemtime($cronLog) : 0;

        // Worker/queue liveness (surfaced as data; not part of core readiness).
        // Every block is guarded so a missing table or pre-migration schema can
        // never take the endpoint down.
        $autopostHeartbeat = dirname(__DIR__, 2) . '/storage/logs/autopost.heartbeat';
        $lastAutopost = is_file($autopostHeartbeat) ? @filemtime($autopostHeartbeat) : 0;

        $autopostFailed24h = 0;
        $autopostBacklog = 0;
        $stuckPhotoEdits = 0;
        $stuckVideoExports = 0;

        try {
            $autopostFailed24h = (int) Database::run(
                "SELECT COUNT(*) FROM auto_poster_queue WHERE status = 'failed' AND created_at >= ?",
                [date('Y-m-d H:i:s', time() - 86400)]
            )->fetchColumn();
        } catch (\Throwable $error) {
            $autopostFailed24h = 0;
        }

        try {
            // Due-but-unclaimed rows (queued, past schedule, not currently
            // being worked by a live claim) = backlog.
            $autopostBacklog = (int) Database::run(
                "SELECT COUNT(*) FROM auto_poster_queue
                 WHERE status = 'queued'
                   AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)
                   AND (claimed_at IS NULL OR claimed_at < ?)",
                [date('Y-m-d H:i:s', time() - 900)]
            )->fetchColumn();
        } catch (\Throwable $error) {
            $autopostBacklog = 0;
        }

        try {
            $stuckPhotoEdits = (int) Database::run(
                "SELECT COUNT(*) FROM photo_edit_jobs
                 WHERE status IN ('queued', 'running') AND started_at < ?",
                [date('Y-m-d H:i:s', time() - 1800)]
            )->fetchColumn();
        } catch (\Throwable $error) {
            $stuckPhotoEdits = 0;
        }

        // Analytics rollup freshness: how old the newest stored day is and
        // whether the last run had to skip log lines. Surfaced as data only.
        $analyticsLastRunAge = null;
        $analyticsLastDay   = null;
        $analyticsSkipped   = 0;

        try {
            $lastRun = Database::run(
                'SELECT MAX(updated_at) AS updated_at, COALESCE(SUM(skipped_lines), 0) AS skipped
                 FROM web_stats_daily'
            )->fetch() ?: [];

            $updatedAt = trim((string) ($lastRun['updated_at'] ?? ''));
            if ($updatedAt !== '') {
                $analyticsLastRunAge = max(0, time() - (int) strtotime($updatedAt));
            }

            $analyticsLastDay = \App\Models\WebStats::lastDayWithData();
            $analyticsSkipped = (int) ($lastRun['skipped'] ?? 0);
        } catch (\Throwable $error) {
            $analyticsLastRunAge = null;
            $analyticsLastDay   = null;
        }

        try {
            $stuckVideoExports = (int) Database::run(
                "SELECT COUNT(*) FROM video_export_jobs
                 WHERE status = 'running' AND started_at < ?",
                [date('Y-m-d H:i:s', time() - 6 * 3600)]
            )->fetchColumn();
        } catch (\Throwable $error) {
            $stuckVideoExports = 0;
        }

        header('Cache-Control: no-store');
        $this->json([
            'ok' => $ok,
            'app' => (string) config('app.site_name', 'gallery'),
            'db' => $db,
            'storage' => $storage,
            'redis' => $redis,
            'backup' => [
                'running' => $running,
                'last_ok_age_sec' => $lastOk > 0 ? time() - $lastOk : null,
            ],
            'housekeeping_age_sec' => $lastHousekeeping > 0 ? time() - $lastHousekeeping : null,
            'workers' => [
                'autopost_heartbeat_age_sec' => $lastAutopost > 0 ? time() - $lastAutopost : null,
                'autopost_failed_24h' => $autopostFailed24h,
                'autopost_backlog' => $autopostBacklog,
                'stuck_photo_edits' => $stuckPhotoEdits,
                'stuck_video_exports' => $stuckVideoExports,
            ],
            'analytics' => [
                'last_run_age_sec' => $analyticsLastRunAge,
                'last_day'         => $analyticsLastDay,
                'skipped_lines'    => $analyticsSkipped,
            ],
            'time' => gmdate('c'),
        ], $ok ? 200 : 503);
    }
}
