<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\RateLimiter;

/**
 * Serves the newest encrypted off-site backup for pickup by the operator's
 * residential machine (the VPS cannot reach the LAN, so the LAN pulls).
 * Bearer-authenticated with BACKUP_PULL_KEY; GET /internal/backup/latest.
 */
class BackupPullController extends Controller
{
    public function latest(): void
    {
        $expected = trim((string) env_value('BACKUP_PULL_KEY', ''));
        $given = trim((string) $this->request->header('Authorization', ''));
        if (stripos($given, 'bearer ') === 0) {
            $given = trim(substr($given, 7));
        }

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->json(['error' => 'Not authorized'], 401);
            return;
        }

        if (!RateLimiter::allow(['backup-pull:' . $this->request->ip()], 10, 600)) {
            $this->json(['error' => 'Too many requests'], 429);
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/backups/offsite';
        $files = glob($dir . '/gallery-*.sql.gz.enc') ?: [];

        if ($files === []) {
            $this->json(['error' => 'No off-site backup available'], 404);
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $file = $files[0];

        $mtime = (int) filemtime($file);

        if (!headers_sent()) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . (int) filesize($file));
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('X-Backup-Mtime: ' . $mtime);
            header('Cache-Control: no-store');
        }

        readfile($file);
        exit;
    }
}