<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
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
            'schemaDiff'  => $this->schemaDiff(),
            'diskFree'    => @disk_free_space($this->storage),
        ]);
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
            if ($file === '.' || $file === '..' || $file === 'pending') {
                continue;
            }
            if (is_dir($uploads . '/' . $file) || isset($known[$file])) {
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

        foreach (glob($this->backupDir . '/*.tar.gz') ?: [] as $file) {
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
        $body = <<<BASH
#!/bin/bash
set -e
trap 'rm -f {BACKUPDIR}/.running' EXIT
DUMP=\$(mktemp /tmp/gallery-dump-XXXXXX.sql)
{MYSQLDUMP} > "\$DUMP"
TARGET={BACKUPDIR}/gallery-backup-{STAMP}.tar.gz
tar czf "\$TARGET" -C {ROOT} storage/uploads
tar rzf "\$TARGET" -C "\$(dirname "\$DUMP")" "\$(basename "\$DUMP")"
rm -f "\$DUMP"
rm -f {BACKUPDIR}/.running
BASH;
        $body = str_replace(
            ['{MYSQLDUMP}', '{BACKUPDIR}', '{STAMP}', '{ROOT}'],
            [$mysqldump, escapeshellarg($this->backupDir), $stamp, escapeshellarg($this->root)],
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
