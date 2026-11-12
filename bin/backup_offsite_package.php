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

// ---------------------------------------------------------------------------
// The secrets. A database dump restores the rows, not the keys that let the
// site talk to PayPal, the mail server or the operators, so a full-box loss
// needed every secret re-supplied by hand. They travel ENCRYPTED with the
// same passphrase, in their own package.
// ---------------------------------------------------------------------------
$secretFiles = [];
foreach ([$root . '/.env', $root . '/storage/autoposter.json'] as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        $secretFiles[] = $candidate;
    }
}

if ($secretFiles !== []) {
    $secretsOut = $outDir . '/gallery-secrets-' . $stamp . '.tar.gz.enc';
    $tarTmp     = tempnam(sys_get_temp_dir(), 'gallery-secrets-');
    $listFile   = tempnam(sys_get_temp_dir(), 'gallery-secrets-list-');

    if ($tarTmp !== false && $listFile !== false) {
        // Relative names inside the archive, so a restore is `tar -xzf`.
        $names = [];
        foreach ($secretFiles as $file) {
            $names[] = ltrim(str_replace($root, '', $file), '/');
        }
        file_put_contents($listFile, implode("\n", $names) . "\n");

        @exec('tar -czf ' . escapeshellarg($tarTmp) . ' -C ' . escapeshellarg($root)
            . ' -T ' . escapeshellarg($listFile) . ' 2>&1', $tarLines, $tarRc);

        if ($tarRc === 0 && is_file($tarTmp) && filesize($tarTmp) > 128) {
            @exec('openssl enc -aes-256-cbc -pbkdf2 -salt -in ' . escapeshellarg($tarTmp)
                . ' -out ' . escapeshellarg($secretsOut)
                . ' -pass file:' . escapeshellarg($passFile) . ' 2>&1', $encLines, $encRc);

            if ($encRc !== 0 || !is_file($secretsOut) || filesize($secretsOut) < 128) {
                @unlink($secretsOut);
                fwrite(STDERR, "WARNING: the secrets package could not be encrypted\n");
            } else {
                @chmod($secretsOut, 0644);
                echo 'packaged ' . basename($secretsOut) . ' (' . count($secretFiles) . ' secret files, '
                    . filesize($secretsOut) . " bytes encrypted)\n";
            }
        } else {
            fwrite(STDERR, "WARNING: the secrets tar could not be built\n");
        }

        @unlink($tarTmp);
        @unlink($listFile);

        // Prune old secrets packages with the same retention.
        $oldSecrets = glob($outDir . '/gallery-secrets-*.tar.gz.enc') ?: [];
        usort($oldSecrets, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($oldSecrets, $keep) as $old) {
            @unlink($old);
        }
    }
}

echo 'packaged ' . basename($out) . ' (' . filesize($out) . ' bytes) from ' . basename($src) . "\n";
exit(0);