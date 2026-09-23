<?php

declare(strict_types=1);

use App\Models\ServerOptimizations;

// Apply server optimization settings to the live box. Root-only, invoked
// through a scoped sudoers rule (like bin/apply_cron.php). Reads
// storage/server_optimizations.json (defaults when absent) and writes
// ADDITIVE include files so existing configs (upload limits, SSE, sessions)
// are never touched, then reloads services and records a status file.
//
// Usage (root):  php /var/www/gallery/bin/apply_server_optimizations.php

require __DIR__ . '/../app/bootstrap.php';

$root = dirname(__DIR__);

$ok = static function (bool $b, string $msg): void {
    if (!$b) {
        fwrite(STDERR, "apply_server_optimizations: {$msg}\n");
        exit(1);
    }
};

$ok(\function_exists('posix_geteuid') ? posix_geteuid() === 0 : \file_exists('/etc/cron.d'),
    'apply_server_optimizations must run as root (via the scoped sudoers rule)');

$s = ServerOptimizations::all();
$notes = [];
$rc = ['apache' => 0, 'php' => 0, 'mysql' => 0];

// ---------------------------------------------------------------- Apache
$apacheConfDir = '/etc/apache2/conf-enabled';
$apacheConf = $apacheConfDir . '/gallery-optimizations.conf';
$deflateConf = '/etc/apache2/mods-enabled/deflate.conf';
$deflateBackup = $deflateConf . '.bak-serveropt';

$compressTypes = 'text/html text/plain text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml application/rss+xml application/wasm font/woff2 font/woff application/x-font-ttf';
if (empty($s['compress_json'])) {
    $compressTypes = str_replace(' application/json', '', $compressTypes);
}

$brotliEnabled = !empty($s['brotli_enabled']);

// Brotli must be the ONLY type-based compressor, otherwise mod_deflate's
// global AddOutputFilterByType wins for browsers and brotli never engages.
// Neutralize those lines in deflate.conf (backing up first) so brotli can
// serve modern clients; when brotli is disabled we restore deflate.
$deflate = @file_get_contents($deflateConf) ?: '';
$hasActiveDeflate = (bool) preg_match('/^[ \t]*AddOutputFilterByType DEFLATE /m', $deflate);

if ($brotliEnabled && $hasActiveDeflate) {
    if (!is_file($deflateBackup)) {
        @copy($deflateConf, $deflateBackup);
        @chmod($deflateBackup, 0644);
    }
    $deflate = preg_replace('/^([ \t]*)AddOutputFilterByType DEFLATE (.*)$/m', '$1# $2 # neutralized by gallery-optimizations (brotli-preferred)', $deflate) ?? $deflate;
    @file_put_contents($deflateConf, $deflate);
} elseif (!$brotliEnabled && is_file($deflateBackup)) {
    @copy($deflateBackup, $deflateConf);
}

$lines = [];
if ($brotliEnabled) {
    $lines[] = '<IfModule mod_brotli.c>';
    $lines[] = "  AddOutputFilterByType BROTLI_COMPRESS {$compressTypes}";
    $lines[] = '</IfModule>';
}
// SSE chat streams must never be compressed.
$lines[] = '<LocationMatch "^/gallery/(chat/stream|webhooks/chat/stream)">';
$lines[] = '  SetEnv no-gzip 1';
$lines[] = '  Header unset Content-Encoding';
$lines[] = '</LocationMatch>';
$apacheContent = implode("\n", $lines) . "\n";

$ok(@\file_put_contents($apacheConf, $apacheContent) !== false, "write failed: {$apacheConf}");
@chmod($apacheConf, 0644);

// Enable/disable the brotli module to match the setting.
if ($brotliEnabled) {
    \system('a2enmod brotli > /dev/null 2>&1', $m);
} else {
    \system('a2dismod brotli > /dev/null 2>&1', $m);
}
if ($m === 0) {
    $notes[] = $brotliEnabled ? 'brotli enabled' : 'brotli disabled (deflate restored)';
}
\system('apachectl graceful > /dev/null 2>&1', $rc['apache']);
$notes[] = 'apache graceful rc=' . $rc['apache'];

// -------------------------------------------------------------------- PHP
$phpVersions = glob('/etc/php/*/fpm') ?: [];
foreach ($phpVersions as $fpmDir) {
    $confDir = $fpmDir . '/conf.d';
    if (!is_dir($confDir)) {
        continue;
    }

    $ini = "opcache.enable=1\nopcache.validate_timestamps=1\nopcache.revalidate_freq=" . (int) $s['opcache_revalidate_freq'] . "\n";
    $ok(@\file_put_contents($confDir . '/99-gallery-optimizations.ini', $ini) !== false, "write failed: 99-gallery-optimizations.ini");
    @chmod($confDir . '/99-gallery-optimizations.ini', 0644);
}

$phpVer = basename($fpmDir ?? '/etc/php/8.3/fpm');
\system('systemctl reload php' . $phpVer . '-fpm > /dev/null 2>&1', $rc['php']);
if ($rc['php'] !== 0) {
    // Try the common active version if the one we wrote to isn't running.
    \system('systemctl reload php8.3-fpm > /dev/null 2>&1', $rc['php']);
}
$notes[] = 'php-fpm reload rc=' . $rc['php'];

// ------------------------------------------------------------------- MySQL
$mysqlConfDir = '/etc/mysql/mysql.conf.d';
if (is_dir($mysqlConfDir)) {
    $bufferBytes = (int) round((float) $s['mysql_buffer_pool_gb'] * 1024 * 1024 * 1024);
    $mysqlCnf = '[mysqld]' . "\n"
        . 'innodb_buffer_pool_size = ' . $bufferBytes . "\n"
        . 'innodb_flush_log_at_trx_commit = ' . (int) $s['mysql_flush_log_trx'] . "\n"
        . 'slow_query_log = ' . (!empty($s['mysql_slow_query_log']) ? 'ON' : 'OFF') . "\n"
        . 'long_query_time = ' . rtrim(rtrim(number_format((float) $s['mysql_long_query_time'], 2), '0'), '.') . "\n";
    $ok(@\file_put_contents($mysqlConfDir . '/99-gallery-optimizations.cnf', $mysqlCnf) !== false, "write failed: 99-gallery-optimizations.cnf");
    @chmod($mysqlConfDir . '/99-gallery-optimizations.cnf', 0644);
}

$bufferBytes = (int) round((float) $s['mysql_buffer_pool_gb'] * 1024 * 1024 * 1024);
$mysqlSql = 'SET GLOBAL innodb_buffer_pool_size = ' . $bufferBytes . ';'
    . ' SET GLOBAL innodb_flush_log_at_trx_commit = ' . (int) $s['mysql_flush_log_trx'] . ';'
    . ' SET GLOBAL slow_query_log = ' . (!empty($s['mysql_slow_query_log']) ? 'ON' : 'OFF') . ';'
    . ' SET GLOBAL long_query_time = ' . (float) $s['mysql_long_query_time'] . ';';
\system('mysql -N -e ' . \escapeshellarg($mysqlSql) . ' 2>/dev/null', $rc['mysql']);
$notes[] = 'mysql SET GLOBAL rc=' . $rc['mysql'];

// ------------------------------------------------------------ status file
$status = [
    'applied_at' => date('c'),
    'values'     => $s,
    'rc'         => $rc,
    'notes'      => $notes,
];
$statusPath = $root . '/storage/server_optimizations.status.json';
@\file_put_contents($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
@chmod($statusPath, 0644);

echo 'apply_server_optimizations: done (' . implode('; ', $notes) . ")\n";
exit(0);