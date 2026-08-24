<?php

namespace App\Core;

/**
 * Scheduled housekeeping shared by the admin "Run now" button and the
 * unattended cron endpoint: expire stale subscriptions, remove long-abandoned
 * staging directories, prune old backups, and record what happened.
 */
class Housekeeping
{
    /**
     * Run every task and return a summary array. $backupKeep keeps the N
     * newest archives when greater than zero; when null it falls back to
     * the HOUSEKEEPING_KEEP_BACKUPS .env value (default 10).
     */
    public static function run(?int $backupKeep = null): array
    {
        $root = dirname(__DIR__, 2);

        if ($backupKeep === null) {
            $backupKeep = (int) env_value('HOUSEKEEPING_KEEP_BACKUPS', '10');
        }

        $out  = [
            'at'            => date('Y-m-d H:i:s'),
            'expired_subs'  => 0,
            'pending_dirs'  => 0,
            'backups_pruned' => 0,
            'disk_free_gb'  => null,
        ];

        self::watchBackupSync($root);
        self::watchRestoreDrill($root);

        // Subscriptions whose expiry passed while nobody was watching.
        $stmt = Database::run(
            "UPDATE subscriptions SET status = 'expired'
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP"
        );
        $out['expired_subs'] = $stmt ? $stmt->rowCount() : 0;

        // Staging directories abandoned mid-upload for more than 72 hours.
        $base = $root . '/storage/uploads/pending';

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $ageH = (time() - (int) filemtime($dir)) / 3600;

            if ($ageH >= 72) {
                self::rrmdir($dir);
                $out['pending_dirs']++;
            }
        }

        // Keep only the newest backup runs so the disk never fills up. A
        // run groups everything sharing one timestamp: the split media
        // archive (.part-NN), its .sha256 checksums and the SQL dump.
        $backupDir = $root . '/storage/backups';
        $runs      = [];

        foreach (['*.tar.gz', '*.tar.gz.part-*', '*.tar.gz.sha256', '*.sql.gz'] as $pattern) {
            foreach ((glob($backupDir . '/' . $pattern) ?: []) as $file) {
                $key = 'misc';

                if (preg_match('/-(\d{8}-\d{6})\./', basename($file), $m)) {
                    $key = $m[1];
                }

                $runs[$key][] = $file;
            }
        }

        if ($backupKeep > 0 && count($runs) > $backupKeep) {
            $newest = fn (array $files): int => max(array_map(fn (string $f): int => (int) filemtime($f), $files));
            uasort($runs, fn (array $a, array $b): int => $newest($b) <=> $newest($a));

            foreach (array_slice($runs, $backupKeep, null, true) as $files) {
                foreach ($files as $old) {
                    @unlink($old);
                    $out['backups_pruned']++;
                }
            }
        }

        // Storage snapshot for the trending chart + low-disk alert.
        // photos_count/video_count mirror the dashboard's Photos/Videos
        // cards (attached, non-deleted media) so the numbers agree.
        $bytes = self::uploadsBytes($root . '/storage/uploads');
        [$photos, $videos] = self::mediaCounts();
        Database::run(
            'INSERT INTO storage_snapshots (captured_at, uploads_bytes, photos_count, video_count) VALUES (CURRENT_TIMESTAMP, ?, ?, ?)',
            [$bytes, $photos, $videos]
        );
        Database::run(
            'DELETE FROM storage_snapshots WHERE captured_at < ?',
            [date('Y-m-d H:i:s', time() - 90 * 86400)]
        );

        $free = @disk_free_space($root);

        if ($free !== false) {
            $freeGb            = round($free / 1073741824, 1);
            $out['disk_free_gb'] = $freeGb;

            if ($freeGb <= (float) env_value('DISK_MIN_FREE_GB', '10')) {
                Mailer::adminAlert(
                    'disk-low',
                    'Low disk space',
                    sprintf("Only %s GB free on the gallery server (threshold %s GB).\nClean up or extend the volume soon.",
                        $freeGb, env_value('DISK_MIN_FREE_GB', '10')),
                    43200
                );
            }
        }

        @file_put_contents(
            $root . '/storage/logs/cron.log',
            implode(' | ', array_map(fn ($k, $v) => "$k=$v", array_keys($out), $out)) . "\n",
            FILE_APPEND
        );

        return $out;
    }

    /**
     * Detect a failed background backup (the runner leaves .failed behind
     * when it exits without success). First caller gets the message and the
     * marker is renamed to .failed.seen so admins are alerted once.
     */
    public static function consumeBackupFailure(): ?string
    {
        $root  = dirname(__DIR__, 2);
        $file  = $root . '/storage/backups/.failed';

        if (!is_file($file)) {
            return null;
        }

        $msg = trim((string) @file_get_contents($file));
        @rename($file, $file . '.seen');
        Mailer::adminAlert('backup-failed', 'Backup failed', "The scheduled/backup job reported failure:\n" . ($msg ?: '(no detail)'), 600);

        return $msg !== '' ? $msg : 'backup failed';
    }

    /**
     * Total size of the uploads tree + photo-file count, walking with the
     * SPL iterators (no shell out).
     *
     * @return array{0: int, 1: int} [bytes, fileCount]
     */
    /**
     * Alert when the offsite backup sync looks unhealthy: the .last_sync
     * status file reports a failure, or no successful sync for over 26 h.
     */
    private static function watchBackupSync(string $root): void
    {
        $file = $root . '/storage/backups/.last_sync';
        if (!is_file($file)) {
            return; // sync feature not configured
        }

        $data  = json_decode((string) @file_get_contents($file), true);
        $ok    = is_array($data) && !empty($data['ok']) && (int) ($data['sync_rc'] ?? 1) === 0;
        $ageH  = is_array($data) && !empty($data['at']) ? (time() - strtotime((string) $data['at'])) / 3600 : null;

        if ($ok && $ageH !== null && $ageH <= 26) {
            return;
        }

        $why = !$ok ? 'last sync reported failure (sync_rc=' . ($data['sync_rc'] ?? '?') . ')'
                    : 'no successful sync for ' . round((float) $ageH) . ' hours';
        Mailer::adminAlert(
            'backup-sync',
            'Backup sync unhealthy',
            "Offsite backup sync problem: {$why}.\nCheck storage/backups/.last_sync and the rclone logs on the server.",
            43200
        );
    }

    /**
     * Alert when the weekly restore drill has not passed recently — the
     * proof that backups are actually restorable.
     */
    private static function watchRestoreDrill(string $root): void
    {
        $file = $root . '/storage/backups/.last_drill';

        if (!is_file($file)) {
            Mailer::adminAlert(
                'restore-drill',
                'Restore drill never run',
                "No backup restore drill has been recorded yet.\nRun scripts/restore-drill.sh from cron to verify restorability.",
                604800
            );
            return;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        $ageD = is_array($data) && !empty($data['at']) ? (time() - strtotime((string) $data['at'])) / 86400 : null;

        if (is_array($data) && !empty($data['ok']) && $ageD !== null && $ageD <= 8) {
            return;
        }

        $why = !is_array($data) || empty($data['ok'])
            ? 'last drill FAILED: ' . ($data['note'] ?? 'unknown error')
            : 'last successful drill was ' . round((float) $ageD) . ' days ago';
        Mailer::adminAlert(
            'restore-drill',
            'Restore drill stale or failed',
            "Backup restore drill problem: {$why}\nCheck storage/backups/.last_drill on the server.",
            604800
        );
    }

    private static function uploadsBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $bytes = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        ) as $item) {
            if ($item->isFile()) {
                $bytes += $item->getSize();
            }
        }

        return $bytes;
    }

    /**
     * Live media counts matching the dashboard's Photos and Videos cards:
     * media attached to at least one non-deleted gallery.
     *
     * @return array{0: int, 1: int} [photos, videos]
     */
    private static function mediaCounts(): array
    {
        $photos = (int) Database::run(
            'SELECT COUNT(DISTINCT gp.photo_id) AS c
             FROM gallery_photo gp JOIN galleries g ON g.id = gp.gallery_id
             WHERE g.deleted_at IS NULL'
        )->fetch()['c'];

        $videos = (int) Database::run(
            'SELECT COUNT(DISTINCT gp.photo_id) AS c
             FROM gallery_photo gp
             JOIN galleries g ON g.id = gp.gallery_id
             JOIN photos p ON p.id = gp.photo_id
             WHERE g.deleted_at IS NULL AND p.is_video = 1'
        )->fetch()['c'];

        return [$photos, $videos];
    }

    private static function rrmdir(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
