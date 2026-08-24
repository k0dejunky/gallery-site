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
     * Run every task and return a summary array. $pruneBackups keeps the N
     * newest archives when greater than zero.
     */
    public static function run(int $backupKeep = 10): array
    {
        $root = dirname(__DIR__, 2);
        $out  = [
            'at'            => date('Y-m-d H:i:s'),
            'expired_subs'  => 0,
            'pending_dirs'  => 0,
            'backups_pruned' => 0,
        ];

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

        // Keep only the newest backup archives so the disk never fills up.
        $backupDir = $root . '/storage/backups';
        $archives  = glob($backupDir . '/*.tar.gz') ?: [];

        if ($backupKeep > 0 && count($archives) > $backupKeep) {
            usort($archives, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

            foreach (array_slice($archives, $backupKeep) as $old) {
                @unlink($old);
                $out['backups_pruned']++;
            }
        }

        @file_put_contents(
            $root . '/storage/logs/cron.log',
            implode(' | ', array_map(fn ($k, $v) => "$k=$v", array_keys($out), $out)) . "\n",
            FILE_APPEND
        );

        return $out;
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
