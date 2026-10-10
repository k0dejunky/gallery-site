<?php

declare(strict_types=1);

// Encrypt the newest local DB backup for OFF-SITE pickup, and prune old copies.
// Runs on the VPS after the nightly dump (cron). The operator's residential
// machine PULLS the newest *.enc over the authenticated endpoint, because the
// VPS cannot reach the LAN.
//
// Passphrase: /etc/gallery-backup.pass (or BACKUP_PASSPHRASE_FILE env), mode
// 640 root:www-data. Keep a copy of the passphrase offline — without it the
// off-site copies cannot be restored.
//
// Usage: php bin/backup_offsite_package.php [--keep=7]

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';

$keep = 7;
foreach ($argv as $a) {
    if (strpos($a, '--keep=') === 0) {
        $keep = max(1, (int) substr($a, 7));
    }
}

$passFile = trim((string) (getenv('BACKUP_PASSPHRASE_FILE') ?: '')) ?: '/etc/gallery-backup.pass';

if (!is_file($passFile) || !is_readable($passFile)) {
    fwrite(STDERR, "ERROR: passphrase file not readable: $passFile\n");
    exit(1);
}

// Newest daily DB dump, else the newest full dump.
$candidates = array_merge(
    glob($root . '/storage/backups/db/gallery-db-*.sql.gz') ?: [],
    glob($root . '/storage/backups/gallery-db-*.sql.gz') ?: []
);
if ($candidates === []) {
    fwrite(STDERR, "No source dump found under storage/backups.\n");
    exit(1);
}
usort($candidates, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
$src = $candidates[0];

$outDir = $root . '/storage/backups/offsite';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0775, true);
}

$stamp = date('Ymd-His');
$out   = $outDir . '/gallery-' . $stamp . '.sql.gz.enc';

$cmd = 'openssl enc -aes-256-cbc -pbkdf2 -salt -in ' . escapeshellarg($src)
    . ' -out ' . escapeshellarg($out) . ' -pass file:' . escapeshellarg($passFile) . ' 2>&1';

@exec($cmd, $lines, $rc);

if ($rc !== 0 || !is_file($out) || filesize($out) < 128) {
    @unlink($out);
    fwrite(STDERR, 'Encryption failed: ' . implode(' ', $lines) . "\n");
    exit(1);
}
@chmod($out, 0644);

// Prune old encrypted copies (keep newest N).
$existing = glob($outDir . '/gallery-*.sql.gz.enc') ?: [];
usort($existing, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
foreach (array_slice($existing, $keep) as $old) {
    @unlink($old);
}

echo 'packaged ' . basename($out) . ' (' . filesize($out) . ' bytes) from ' . basename($src) . "\n";
exit(0);