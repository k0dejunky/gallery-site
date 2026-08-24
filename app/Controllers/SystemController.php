<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Housekeeping;
use App\Models\AuditLog;

/**
 * Admin "System" page: housekeeping tools that would otherwise need SSH —
 * orphaned-upload cleanup, one-click backups, and a schema health check that
 * diffs schema.sql against the live database.
 */
class SystemController extends Controller
{
    private string $root;
    private string $storage;
    private string $backupDir;

    public function __construct($request)
    {
        parent::__construct($request);
        Auth::requirePermission('logs');

        $this->root      = dirname(__DIR__, 2);
        $this->storage   = $this->root . '/storage';
        $this->backupDir = $this->storage . '/backups';

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0775, true);
        }
    }

    public function index(): void
    {
        $this->viewAdmin('system', [
            'pendingDirs' => $this->pendingDirs(),
            'orphans'     => $this->orphanFiles(),
            'backups'     => $this->backups(),
            'backupRunning' => file_exists($this->backupDir . '/.running'),
            'backupFailure' => Housekeeping::consumeBackupFailure(),
            'lastSync'    => $this->lastSyncStatus(),
            'variants'    => $this->variantStats(),
            'dbTables'    => $this->tableSizes(),
            'maintenance' => is_file($this->storage . '/maintenance.flag'),
            'cronKeySet'  => self::cronKey() !== '',
            'cronAgeMin'  => $this->cronLastRunMinutes(),
            'security'    => \App\Models\Stats::security(),
            'storageTrend' => \App\Models\Stats::storageTrend(),
            'retention'   => (int) env_value('HOUSEKEEPING_KEEP_BACKUPS', '10'),
            'schemaDiff'  => $this->schemaDiff(),
            'diskFree'    => @disk_free_space($this->storage),
        ]);
    }

    /**
     * Decode storage/backups/.last_sync written by the backup runner:
     * archive verification result + offsite copy result.
     */
    private function lastSyncStatus(): ?array
    {
        $file = $this->backupDir . '/.last_sync';

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Minutes since the last housekeeping cron entry; null when the cron
     * has never run (fresh install or key not configured).
     */
    private function cronLastRunMinutes(): ?int
    {
        $log = $this->storage . '/logs/cron.log';

        if (!is_file($log)) {
            return null;
        }

        return (int) round((time() - (int) filemtime($log)) / 60);
    }

    // ------------------------------------------------------------------
    // Media variants
    // ------------------------------------------------------------------

    private function variantStats(): array
    {
        $rows  = Database::run('SELECT filename, is_video FROM photos')->fetchAll();
        $missingThumb = 0;
        $missingWeb   = 0;
        $broken       = 0;

        foreach ($rows as $row) {
            $src = $this->storage . '/uploads/' . $row['filename'];

            if (!is_file($src)) {
                $broken++;
                continue;
            }
            if (!is_file($this->storage . '/uploads/thumb_' . $row['filename'])) {
                $missingThumb++;
            }
            if (empty($row['is_video']) && !is_file($this->storage . '/uploads/web_' . $row['filename'])) {
                $missingWeb++;
            }
        }

        return [
            'total'         => count($rows),
            'missing_thumb' => $missingThumb,
            'missing_web'   => $missingWeb,
            'broken'        => $broken,
            'running'       => file_exists($this->backupDir . '/.variants'),
        ];
    }

    /**
     * Rebuild every missing thumbnail/web variant in a detached background
     * process (hundreds of ffmpeg/GD passes would outlive any request).
     */
    public function variantsRegenerate(): void
    {
        $marker = $this->backupDir . '/.variants';

        if (file_exists($marker)) {
            $this->flash('error', 'Variant regeneration is already running.');
            $this->redirect('/admin/system');
        }

        @touch($marker);
        @mkdir($this->storage . '/logs', 0775, true);

        // The worker script lives in the project so admins can also run it
        // by hand over SSH; a tiny bash runner clears the marker on exit.
        $worker = $this->storage . '/scripts/regen_variants.php';
        @mkdir(dirname($worker), 0775, true);
        file_put_contents($worker, $this->regenScriptBody());
        chmod($worker, 0644);

        $script = sys_get_temp_dir() . '/gallery-variants.sh';
        file_put_contents($script, '#!/bin/bash' . "\n"
            . escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($worker) . "\n"
            . 'rm -f ' . escapeshellarg($marker) . "\n");
        chmod($script, 0700);

        AuditLog::record(Auth::user()['id'] ?? null, 'create', 'system_variants', null,
            'Started background media variant regeneration');
        @shell_exec('setsid nohup bash ' . escapeshellarg($script)
            . ' > ' . escapeshellarg($this->storage . '/logs/variants.log') . ' 2>&1 &');

        $this->flash('success', 'Variant regeneration started — refresh this page for progress.');
        $this->redirect('/admin/system');
    }

    /**
     * Standalone worker: regenerate missing thumb_/web_ variants for every
     * photo row. Safe to re-run; identical to the CLI repair script.
     */
    private function regenScriptBody(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$root = __DIR__ . '/../..';
require $root . '/app/Core/helpers.php';
$config = require $root . '/config/app.php';
$uploadsDir = $config['uploads']['dir'];
$env = parse_ini_file($root . '/.env');
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['GALLERY_DB_HOST'], $env['GALLERY_DB_PORT'], $env['GALLERY_DB_NAME']),
    $env['GALLERY_DB_USER'], $env['GALLERY_DB_PASSWORD'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$rows = $pdo->query('SELECT filename, is_video FROM photos')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $src = $uploadsDir . '/' . $row['filename'];
    if (!is_file($src)) continue;
    $thumb = $uploadsDir . '/thumb_' . $row['filename'];
    $web   = $uploadsDir . '/web_' . $row['filename'];
    if (!empty($row['is_video'])) {
        if (!is_file($thumb)) create_video_thumbnail($src, $thumb, $config['uploads']['thumb_width'], $config['uploads']['thumb_height']);
        continue;
    }
    if (!is_file($thumb) || !is_file($web)) {
        create_image_variants($src, $web, $thumb, $config['uploads']['web_max_width'], $config['uploads']['thumb_width'], $config['uploads']['thumb_height']);
        echo '.', flush();
    }
}
echo "\nDONE\n";
PHP;
    }

    // ------------------------------------------------------------------
    // Database tools
    // ------------------------------------------------------------------

    private function tableSizes(): array
    {
        $db = config('database');

        return Database::run(
            'SELECT table_name AS name, table_rows AS `rows`,
                    ROUND((data_length + index_length) / 1048576, 1) AS size_mb
             FROM information_schema.tables
             WHERE table_schema = ?
             ORDER BY (data_length + index_length) DESC',
            [$db['database'] ?? '']
        )->fetchAll();
    }

    public function dbOptimize(): void
    {
        $allowed = array_flip(array_map('strtolower',
            Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN)));
        $target = (string) $this->request->post('table', '');
        $done   = [];

        if ($target === '__all') {
            foreach (array_keys($allowed) as $name) {
                Database::run('OPTIMIZE TABLE `' . str_replace('`', '', $name) . '`');
                $done[] = $name;
            }
        } elseif (isset($allowed[strtolower($target)])) {
            Database::run('OPTIMIZE TABLE `' . str_replace('`', '', $target) . '`');
            $done[] = $target;
        } else {
            $this->flash('error', 'Unknown table.');
            $this->redirect('/admin/system');
        }

        AuditLog::record(Auth::user()['id'] ?? null, 'update', 'system_db', null,
            'Optimized table(s): ' . implode(', ', $done));
        $this->flash('success', 'Optimized ' . count($done) . ' table(s).');
        $this->redirect('/admin/system');
    }

    // ------------------------------------------------------------------
    // Maintenance mode & housekeeping
    // ------------------------------------------------------------------

    public function maintenanceToggle(): void
    {
        $flag = $this->storage . '/maintenance.flag';
        $on   = (string) $this->request->post('mode', '');

        if ($on === 'on') {
            @touch($flag);
            AuditLog::record(Auth::user()['id'] ?? null, 'update', 'system_maintenance', null, 'Maintenance mode ENABLED');
            $this->flash('success', 'Maintenance mode is ON — only staff can browse the site.');
        } else {
            @unlink($flag);
            AuditLog::record(Auth::user()['id'] ?? null, 'update', 'system_maintenance', null, 'Maintenance mode disabled');
            $this->flash('success', 'Site back to normal.');
        }

        $this->redirect('/admin/system');
    }

    public function housekeepingRun(): void
    {
        $summary = \App\Core\Housekeeping::run();
        AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_housekeeping', null,
            'Manual housekeeping: ' . json_encode($summary));
        $this->flash('success', sprintf(
            'Housekeeping done — %d sub(s) expired, %d stale staging dir(s) removed, %d old backup(s) pruned.',
            $summary['expired_subs'], $summary['pending_dirs'], $summary['backups_pruned']
        ));
        $this->redirect('/admin/system');
    }

    /** The shared cron secret from .env (never displayed, only its presence). */
    public static function cronKey(): string
    {
        return \env_value('GALLERY_CRON_KEY');
    }

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------

    /**
     * Staging directories under storage/uploads/pending: name, size, age.
     */
    private function pendingDirs(): array
    {
        $base = $this->storage . '/uploads/pending';
        $out  = [];

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $size = 0;
            $count = 0;

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            ) as $file) {
                $size += $file->getSize();
                $count++;
            }

            $out[] = [
                'name'  => basename($dir),
                'size'  => $size,
                'files' => $count,
                'age_h' => (int) ((time() - (int) filemtime($dir)) / 3600),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['age_h'] <=> $a['age_h']);

        return $out;
    }

    /**
     * Files sitting in the uploads root that no photos row references.
     * Variants share the canonical filename, so any known name protects
     * all of its generated sizes.
     */
    private function orphanFiles(): array
    {
        $uploads = $this->storage . '/uploads';
        $known = Database::run('SELECT filename FROM photos')->fetchAll(\PDO::FETCH_COLUMN);
        $known = array_flip((array) $known);
        $orphans = [];

        foreach (scandir($uploads) ?: [] as $file) {
            if ($file === '.' || $file === '..' || $file === 'pending' || $file === 'exports') {
                continue;
            }
            if (is_dir($uploads . '/' . $file) || isset($known[$file])) {
                continue;
            }
            // Generated variants (thumb_/web_ prefixes) are never orphans:
            // they derive from a canonical name and are rebuilt on demand,
            // so deleting them here would break every thumbnail sitewide.
            if (strpos($file, 'thumb_') === 0 || strpos($file, 'web_') === 0) {
                continue;
            }
            $orphans[] = ['name' => $file, 'size' => (int) filesize($uploads . '/' . $file)];
        }

        usort($orphans, fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $orphans;
    }

    public function cleanupPending(): void
    {
        $base  = realpath($this->storage . '/uploads/pending');
        $token = (string) $this->request->post('dir', '');
        $removed = 0;

        if ($token === 'all') {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                self::rrmdir($dir);
                $removed++;
            }
        } elseif ($token !== '') {
            $target = realpath($base . '/' . basename($token));
            if ($target !== false && strncmp($target, (string) $base, strlen((string) $base)) === 0) {
                self::rrmdir($target);
                $removed++;
            }
        }

        AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_pending_uploads', null,
            'Removed ' . $removed . ' pending upload folder(s)', null, ['removed' => $removed]);
        $this->flash('success', 'Removed ' . $removed . ' pending upload folder(s).');
        $this->redirect('/admin/system');
    }

    public function cleanupOrphans(): void
    {
        $orphans = $this->orphanFiles();
        $removed = 0;

        foreach ($orphans as $orphan) {
            $path = $this->storage . '/uploads/' . basename((string) $orphan['name']);
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }

        AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_orphan_files', null,
            'Removed ' . $removed . ' orphaned upload file(s)', null, ['removed' => $removed]);
        $this->flash('success', 'Removed ' . $removed . ' orphaned file(s).');
        $this->redirect('/admin/system');
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

    // ------------------------------------------------------------------
    // Backups
    // ------------------------------------------------------------------

    private function backups(): array
    {
        $out = [];

        foreach (array_merge(
            glob($this->backupDir . '/*.tar.gz') ?: [],
            glob($this->backupDir . '/*.sql.gz') ?: []
        ) as $file) {
            $out[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'time' => (int) filemtime($file),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return $out;
    }

    public function backupCreate(): void
    {
        if (file_exists($this->backupDir . '/.running')) {
            $this->flash('error', 'A backup is already running.');
            $this->redirect('/admin/system');
        }

        // Multi-GB libraries take many minutes, so run detached and let the
        // user watch the list below; .running marks work in progress.
        @touch($this->backupDir . '/.running');
        @mkdir($this->storage . '/logs', 0775, true);

        $db    = config('database');
        $stamp = date('Ymd-His');

        $mysqldump = 'mysqldump --single-transaction --quick --no-tablespaces'
            . ' -h ' . escapeshellarg((string) ($db['host'] ?? '127.0.0.1'))
            . ' -P ' . (int) ($db['port'] ?? 3306)
            . ' -u ' . escapeshellarg((string) ($db['username'] ?? ''))
            . ' -p' . escapeshellarg((string) ($db['password'] ?? ''))
            . ' ' . escapeshellarg((string) ($db['database'] ?? ''));

        $script = sys_get_temp_dir() . '/gallery-backup.sh';
        // NOTE: GNU tar cannot append to a compressed archive, so the SQL
        // dump ships as its own .sql.gz next to the media .tar.gz.
        $body = <<<BASH
#!/bin/bash
set -e
cd {ROOT}
trap 'rm -f {BACKUPDIR}/.running; if [ ! -f {BACKUPDIR}/.last_ok ]; then echo "\$(date "+%F %T") backup aborted (dump/tar/verify failed)" >> {BACKUPDIR}/.failed; fi' EXIT
rm -f {BACKUPDIR}/.failed {BACKUPDIR}/.last_ok
DUMP=\$(mktemp /tmp/gallery-dump-XXXXXX.sql)
{MYSQLDUMP} > "\$DUMP"
TARGET={BACKUPDIR}/gallery-backup-{STAMP}.tar.gz
SQLT={BACKUPDIR}/gallery-db-{STAMP}.sql.gz
tar czf "\$TARGET" --warning=no-file-changed --ignore-failed-read -C {ROOT} storage/uploads
test \$? -le 1
gzip -c "\$DUMP" > "\$SQLT"
rm -f "\$DUMP"
gzip -t "\$TARGET" || { echo "\$(date "+%F %T") media archive verification failed: \$TARGET" >> {BACKUPDIR}/.failed; exit 1; }
gzip -t "\$SQLT" || { echo "\$(date "+%F %T") db dump verification failed: \$SQLT" >> {BACKUPDIR}/.failed; exit 1; }
SYNC_RC=0
{SYNCBLOCK}
printf '{"ok":true,"at":"%s","file":"%s","db":"%s","sync_rc":%s}\\n' "\$(date +%FT%T)" "\$(basename "\$TARGET")" "\$(basename "\$SQLT")" "\$SYNC_RC" > {BACKUPDIR}/.last_sync
touch {BACKUPDIR}/.last_ok
rm -f {BACKUPDIR}/.running
trap - EXIT
BASH;
        $syncCmd = trim((string) env_value('BACKUP_SYNC_CMD', ''));
        $syncBlock = $syncCmd !== ''
            ? 'if [ -n "{SYNCCMD}" ]; then' . "\n" . '  {SYNCCMD} || SYNC_RC=$?' . "\n" . 'fi'
            : ':';
        $body = str_replace(
            ['{MYSQLDUMP}', '{BACKUPDIR}', '{STAMP}', '{ROOT}', '{SYNCBLOCK}', '{SYNCCMD}'],
            [$mysqldump, escapeshellarg($this->backupDir), $stamp, escapeshellarg($this->root), $syncBlock, $syncCmd],
            $body
        );
        file_put_contents($script, $body);
        chmod($script, 0700);

        // setsid + nohup so the job survives the FPM request ending.
        @shell_exec('setsid nohup bash ' . escapeshellarg($script)
            . ' > ' . escapeshellarg($this->storage . '/logs/backup.log') . ' 2>&1 &');

        AuditLog::record(Auth::user()['id'] ?? null, 'create', 'system_backup', null,
            'Started background backup (' . $stamp . ')');
        $this->flash('success', 'Backup started in the background — refresh this page to check progress.');
        $this->redirect('/admin/system');
    }

    public function backupDownload(string $file): void
    {
        $path = $this->backupDir . '/' . basename($file);

        if (!is_file($path)) {
            $this->notFound();
            return;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public function backupDelete(string $file): void
    {
        $path = $this->backupDir . '/' . basename($file);

        if (is_file($path) && @unlink($path)) {
            AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_backup', null,
                'Deleted backup ' . basename($path));
            $this->flash('success', 'Backup deleted.');
        } else {
            $this->flash('error', 'Could not delete that backup.');
        }

        $this->redirect('/admin/system');
    }

    // ------------------------------------------------------------------
    // Schema health
    // ------------------------------------------------------------------

    /**
     * Diff schema.sql against the live database: missing tables, missing
     * columns and columns present only in the live DB.
     */
    private function schemaDiff(): array
    {
        $sql = @file_get_contents($this->root . '/schema.sql');

        if ($sql === false || $sql === '') {
            return [];
        }

        $wanted = [];
        preg_match_all('/CREATE TABLE[^(]*`?([A-Za-z0-9_]+)`?\s*\((.*?)\)\s*(?:ENGINE|DEFAULT|;)/is', $sql, $tables, PREG_SET_ORDER);

        foreach ($tables as $table) {
            $name = $table[1];
            $cols = [];

            foreach (explode("\n", $table[2]) as $line) {
                $line = trim(trim($line), ",");
                if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT|SPATIAL|CHECK)\b/i', $line)) {
                    continue;
                }
                if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $line, $m)) {
                    $cols[strtolower($m[1])] = true;
                }
            }

            $wanted[strtolower($name)] = $cols;
        }

        $liveTables = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $liveTableNames = array_flip(array_map('strtolower', (array) $liveTables));

        $diff = [];
        foreach ($wanted as $name => $cols) {
            $entry = ['missing_table' => false, 'missing_cols' => [], 'extra_cols' => []];

            if (!isset($liveTableNames[$name])) {
                $entry['missing_table'] = true;
                $diff[$name] = $entry;
                continue;
            }

            $liveCols = Database::run('SHOW COLUMNS FROM `' . str_replace('`', '', $name) . '`')
                ->fetchAll(\PDO::FETCH_COLUMN);
            $liveCols = array_map('strtolower', (array) $liveCols);

            foreach (array_keys($cols) as $col) {
                if (!in_array($col, $liveCols, true)) {
                    $entry['missing_cols'][] = $col;
                }
            }
            foreach ($liveCols as $col) {
                if (!isset($cols[$col])) {
                    $entry['extra_cols'][] = $col;
                }
            }

            if ($entry['missing_cols'] !== [] || $entry['extra_cols'] !== []) {
                $diff[$name] = $entry;
            }
        }

        return $diff;
    }
}
