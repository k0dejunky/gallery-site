<?php
/**
 * CLI backup runner — same logic as the admin "Create backup" button but
 * designed for cron. Reads DB credentials and sync command from .env.
 *
 * Usage:  php bin/gallery_backup.php          # run full backup + sync
 *         php bin/gallery_backup.php --dry-run # show what would happen
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "ERROR: .env not found at $envFile\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$key, $val] = explode('=', $line, 2);
    $env[trim($key)] = trim($val);
}

$get = fn(string $key, string $default = '') => $env[$key] ?? $default;

$dryRun = in_array('--dry-run', $argv, true);

// Paths
$storage   = $root . '/storage';
$backupDir = $storage . '/backups';
$stamp     = date('Ymd-His');

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}

// Check for running backup. A .running marker older than 6 hours is a stale
// lock left by a hard-killed backup (the bash trap cannot run on kill -9);
// reclaim it instead of blocking all future backups forever.
if (is_file($backupDir . '/.running')) {
    $mtime = @filemtime($backupDir . '/.running');
    if ($mtime !== false && time() - (int) $mtime > 6 * 3600) {
        @unlink($backupDir . '/.running');
    } else {
        fwrite(STDERR, "Another backup is already running (.running exists).\n");
        exit(1);
    }
}

// DB credentials
$dbHost = $get('GALLERY_DB_HOST', '127.0.0.1');
$dbPort = $get('GALLERY_DB_PORT', '3306');
$dbUser = $get('GALLERY_DB_USER', 'gallery');
$dbPass = $get('GALLERY_DB_PASSWORD', '');
$dbName = $get('GALLERY_DB_NAME', 'gallery_mvc');

$syncCmd = $get('BACKUP_SYNC_CMD', '');

$mysqldump = 'mysqldump --single-transaction --quick --no-tablespaces'
    . ' -h ' . escapeshellarg($dbHost)
    . ' -P ' . (int) $dbPort
    . ' -u ' . escapeshellarg($dbUser)
    . ' ' . escapeshellarg($dbName);

// Daily DB-only mode: a cheap, frequent snapshot so the database stays
// recoverable between full (monthly) backups. Writes to storage/backups/db/
// (a subdir the full-backup retention globs never match) and prunes dumps
// older than DB_DUMP_KEEP_DAYS. Skips the media tar, verify and offsite sync.
if (in_array('--db-only', $argv, true)) {
    $dbDir = $backupDir . '/db';
    if (!is_dir($dbDir)) {
        @mkdir($dbDir, 0775, true);
    }

    // Respect an in-progress full backup's lock; a stale lock older than 6h
    // is reclaimed below, so never block forever.
    if (is_file($backupDir . '/.running')) {
        $mtime = @filemtime($backupDir . '/.running');
        if ($mtime !== false && time() - (int) $mtime > 6 * 3600) {
            @unlink($backupDir . '/.running');
        } else {
            fwrite(STDERR, "Another backup is already running (.running exists).\n");
            exit(1);
        }
    }

    $dump = $dbDir . "/gallery-db-{$stamp}.sql.gz";
    $rc   = 1;
    $out  = [];
    @exec('MYSQL_PWD=' . escapeshellarg($dbPass) . ' ' . $mysqldump . ' | gzip > ' . escapeshellarg($dump), $out, $rc);

    if ($rc !== 0 || !is_file($dump)) {
        @unlink($dump);
        fwrite(STDERR, "DB-only dump failed\n");
        exit(1);
    }
    @chmod($dump, 0664);

    // Prune dumps older than the retention window.
    $keepDays = max(1, (int) ($get('DB_DUMP_KEEP_DAYS', '30') ?: 30));
    foreach (glob($dbDir . '/gallery-db-*.sql.gz') ?: [] as $old) {
        if (@filemtime($old) < time() - $keepDays * 86400) {
            @unlink($old);
        }
    }

    echo "DB-only dump: {$dump}\n";
    exit(0);
}

// Build the bash backup script
$target = $backupDir . "/gallery-backup-{$stamp}.tar.gz";
$sqlt   = $backupDir . "/gallery-db-{$stamp}.sql.gz";

$syncBlock = '';
if ($syncCmd !== '') {
    $syncBlock = <<<BASH
SYNC_RC=0
if [ -n "{$syncCmd}" ]; then
  RCLONE_CONFIG=/var/www/.config/rclone/rclone.conf {$syncCmd} || SYNC_RC=\$?
fi
BASH;
}

// Pre-flight disk check: the 4 GB parts need roughly the size of the media on
// disk (videos/images don't compress), so abort before starting if there is
// not enough free space — a backup must never fill the disk mid-run.
$mediaBytes = 0;
$duOut = [];
exec('du -sk ' . escapeshellarg($root . '/storage/uploads') . ' 2>/dev/null', $duOut, $duRc);
if ($duRc === 0 && isset($duOut[0])) {
    $mediaBytes = (int) trim((string) preg_split('/\s+/', (string) $duOut[0])[0] ?? '0') * 1024;
}
$freeBytes = @disk_free_space($root);
$requiredBytes = $mediaBytes * 1.05 + 5 * 1073741824;
if ($freeBytes !== false && $freeBytes < $requiredBytes) {
    @file_put_contents($backupDir . '/.failed', date('Y-m-d H:i:s') . " backup aborted (disk pre-flight: need ~"
        . round($requiredBytes / 1073741824, 1) . " GB free, have " . round($freeBytes / 1073741824, 1) . " GB)\n", FILE_APPEND);
    fwrite(STDERR, "Backup aborted: ~" . round($requiredBytes / 1073741824, 1)
        . " GB free needed (media ~" . round($mediaBytes / 1073741824, 1)
        . " GB + margin), only " . round($freeBytes / 1073741824, 1) . " GB available.\n");
    exit(1);
}

$script = <<<BASH
#!/bin/bash
set -e
cd {$root}
trap 'rm -f {$backupDir}/.running {$target}.part-* {$sqlt}; if [ ! -f {$backupDir}/.last_ok ]; then echo "\$(date "+%F %T") backup aborted (dump/tar/verify failed)" >> {$backupDir}/.failed; fi' EXIT
rm -f {$backupDir}/.failed {$backupDir}/.last_ok
DUMP=\$(mktemp /tmp/gallery-dump-XXXXXX.sql)
# DB password goes via MYSQL_PWD (not -p) so it never shows in ps.
MYSQL_PWD='{$dbPass}' {$mysqldump} > "\$DUMP"
TARGET={$target}
SQLT={$sqlt}
# .env deliberately excluded: it holds DB/mail/cron secrets. Back it up
# separately in the secrets vault so a leaked archive cannot expose them.
# Stream the tar straight into 4 GB parts — no single intermediate tar, so the
# backup never needs 2x the media size on disk (videos don't compress).
tar czf - --warning=no-file-changed --ignore-failed-read -C {$root} storage/uploads storage/*.json storage/cron | split -b 4G -d - "\$TARGET.part-"
PARTS=\$(ls -1 "\$TARGET".part-* | wc -l)
test "\$PARTS" -ge 1 || { echo "\$(date "+%F %T") tar/split failed for \$TARGET" >> {$backupDir}/.failed; exit 1; }
# Each part is a chunk of one gzip stream: zcat concatenates + decompresses
# them together to verify the whole archive reads back cleanly.
zcat "\$TARGET".part-* > /dev/null || { echo "\$(date "+%F %T") media archive verification failed: \$TARGET" >> {$backupDir}/.failed; exit 1; }
gzip -c "\$DUMP" > "\$SQLT"
rm -f "\$DUMP"
gzip -t "\$SQLT" || { echo "\$(date "+%F %T") db dump verification failed: \$SQLT" >> {$backupDir}/.failed; exit 1; }
(cd {$backupDir} && sha256sum \$(basename "\$TARGET").part-* > \$(basename "\$TARGET").sha256)
{$syncBlock}
printf '{"ok":true,"at":"%s","file":"%s","parts":%d,"db":"%s","sync_rc":%s}\n' "\$(date +%FT%T)" "\$(basename "\$TARGET")" "\$PARTS" "\$(basename "\$SQLT")" "\${SYNC_RC:-0}" > {$backupDir}/.last_sync
touch {$backupDir}/.last_ok
rm -f {$backupDir}/.running
trap - EXIT
BASH;

if ($dryRun) {
    echo "=== DRY RUN — would execute: ===";
    echo $script;
    exit(0);
}

// Write and execute
$scriptPath = sys_get_temp_dir() . '/gallery-backup-' . $stamp . '.sh';
file_put_contents($scriptPath, $script);
chmod($scriptPath, 0700);

@touch($backupDir . '/.running');
@mkdir($storage . '/logs', 0775, true);

$logFile = $storage . '/logs/backup.log';
$cmd = 'setsid nohup bash ' . escapeshellarg($scriptPath)
    . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';

fwrite(STDOUT, "Backup started (stamp: {$stamp})\n");
fwrite(STDOUT, "Log: {$logFile}\n");
shell_exec($cmd);

// Brief pause then report status
sleep(2);
if (is_file($backupDir . '/.running')) {
    fwrite(STDOUT, "Backup is running in background — check '{$backupDir}' for progress.\n");
} elseif (is_file($backupDir . '/.last_ok')) {
    fwrite(STDOUT, "Backup completed successfully.\n");
} else {
    fwrite(STDERR, "Backup may have failed — check {$logFile} and {$backupDir}/.failed\n");
}
