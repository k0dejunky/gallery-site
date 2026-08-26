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
require $root . '/app/Core/helpers.php';

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

// Check for running backup
if (is_file($backupDir . '/.running')) {
    fwrite(STDERR, "Another backup is already running (.running exists).\n");
    exit(1);
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
    . ' -p' . escapeshellarg($dbPass)
    . ' ' . escapeshellarg($dbName);

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

$script = <<<BASH
#!/bin/bash
set -e
cd {$root}
trap 'rm -f {$backupDir}/.running; if [ ! -f {$backupDir}/.last_ok ]; then echo "\$(date "+%F %T") backup aborted (dump/tar/verify failed)" >> {$backupDir}/.failed; fi' EXIT
rm -f {$backupDir}/.failed {$backupDir}/.last_ok
DUMP=\$(mktemp /tmp/gallery-dump-XXXXXX.sql)
{$mysqldump} > "\$DUMP"
TARGET={$target}
SQLT={$sqlt}
tar czf "\$TARGET" --warning=no-file-changed --ignore-failed-read -C {$root} storage/uploads
test \$? -le 1
gzip -c "\$DUMP" > "\$SQLT"
rm -f "\$DUMP"
gzip -t "\$TARGET" || { echo "\$(date "+%F %T") media archive verification failed: \$TARGET" >> {$backupDir}/.failed; exit 1; }
gzip -t "\$SQLT" || { echo "\$(date "+%F %T") db dump verification failed: \$SQLT" >> {$backupDir}/.failed; exit 1; }
split -b 4G -d "\$TARGET" "\$TARGET.part-"
PARTS=\$(ls -1 "\$TARGET".part-* | wc -l)
test "\$PARTS" -ge 1 || { echo "\$(date "+%F %T") split failed for \$TARGET" >> {$backupDir}/.failed; exit 1; }
(cd {$backupDir} && sha256sum \$(basename "\$TARGET").part-* > \$(basename "\$TARGET").sha256)
rm -f "\$TARGET"
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
