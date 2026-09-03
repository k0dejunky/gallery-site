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
            'cronJobs'    => $this->cronJobs(),
            'cronSchedule'=> $this->cronSchedule(),
            'cronScheduleIsSuper' => (Auth::user()['role'] ?? '') === 'super_admin',
            'security'    => \App\Models\Stats::security(),
            'storageTrend' => \App\Models\Stats::storageTrend(),
            'retention'   => (int) env_value('HOUSEKEEPING_KEEP_BACKUPS', '10'),
            'schemaDiff'  => $this->schemaDiff(),
            'diskFree'    => @disk_free_space($this->storage),
            'diagnostics' => $this->operationalDiagnostics(),
            'exportQueue' => $this->videoExportQueue(),
            'photoEditQueue' => $this->photoEditQueue(),
        ]);
    }

    /** Status values shown only on the existing admin system page. */
    private function operationalDiagnostics(): array
    {
        $diagnostics = [
            'db' => false,
            'storage' => false,
            'smtp' => env_value('MAIL_HOST') !== '' && env_value('MAIL_USERNAME') !== '' && env_value('MAIL_PASSWORD') !== '',
            'paypal' => ['configured' => false, 'enabled' => false],
            'migrations' => ['table' => false, 'applied' => 0, 'pending' => 0],
        ];

        try {
            Database::run('SELECT 1')->fetchColumn();
            $diagnostics['db'] = true;
        } catch (\Throwable $error) {
            return $diagnostics;
        }

        $uploads = (string) config('app.uploads.dir');
        $directories = [$this->storage, $uploads, $uploads . '/pending', $uploads . '/exports'];
        $diagnostics['storage'] = count(array_filter($directories, static function (string $directory): bool {
            return is_dir($directory) && is_readable($directory) && is_writable($directory);
        })) === count($directories);

        try {
            $paypalRows = Database::run(
                "SELECT api_key, secret_key, enabled FROM payment_processors WHERE LOWER(provider) = 'paypal'"
            )->fetchAll();
            foreach ($paypalRows as $row) {
                $configured = trim((string) ($row['api_key'] ?? '')) !== '' && trim((string) ($row['secret_key'] ?? '')) !== '';
                $diagnostics['paypal']['configured'] = $diagnostics['paypal']['configured'] || $configured;
                $diagnostics['paypal']['enabled'] = $diagnostics['paypal']['enabled'] || ($configured && (int) $row['enabled'] === 1);
            }
        } catch (\Throwable $error) {
            // Older installations may not have the payment processor table yet.
        }

        try {
            $hasTable = (bool) Database::run("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
            $diagnostics['migrations']['table'] = $hasTable;
            if ($hasTable) {
                $applied = Database::run('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
                $files = array_map('basename', glob($this->root . '/database/migrations/*.sql') ?: []);
                $diagnostics['migrations']['applied'] = count($applied);
                $diagnostics['migrations']['pending'] = count(array_diff($files, $applied));
            }
        } catch (\Throwable $error) {
            // Keep diagnostics useful when migration metadata is unavailable.
        }

        return $diagnostics;
    }

    /**
     * Video export queue summary: counts by status, stale-running recovery
     * candidate, and the most recent export timestamp.
     */
    private function videoExportQueue(): array
    {
        $summary = [
            'service_active' => false,
            'queued'   => 0,
            'running'  => 0,
            'completed' => 0,
            'failed'   => 0,
            'stale'    => 0,
            'latest'   => null,
        ];

        try {
            $rows = Database::run(
                "SELECT status, COUNT(*) AS c FROM video_export_jobs GROUP BY status"
            )->fetchAll();
            foreach ($rows as $row) {
                $summary[(string) $row['status']] = (int) $row['c'];
            }

            $summary['stale'] = (int) Database::run(
                "SELECT COUNT(*) FROM video_export_jobs WHERE status = 'running' AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 HOUR)"
            )->fetchColumn();

            $latest = Database::run(
                "SELECT finished_at, created_at FROM video_export_jobs ORDER BY COALESCE(finished_at, created_at) DESC LIMIT 1"
            )->fetch();
            if ($latest) {
                $summary['latest'] = $latest['finished_at'] ?? $latest['created_at'];
            }
        } catch (\Throwable $e) {
            // Older installations without the table yet.
        }

        $summary['service_active'] = (bool) @file_exists('/etc/systemd/system/gallery-video-export.service')
            && @filesize('/etc/systemd/system/gallery-video-export.service') > 0;

        return $summary;
    }

    /**
     * Photo edit queue (bulk rotate) summary by status and worker install state.
     */
    private function photoEditQueue(): array
    {
        $summary = [
            'service_active' => false,
            'queued'   => 0,
            'running'  => 0,
            'completed' => 0,
            'failed'   => 0,
            'latest'   => null,
        ];

        try {
            $rows = Database::run(
                "SELECT status, COUNT(*) AS c FROM photo_edit_jobs GROUP BY status"
            )->fetchAll();
            foreach ($rows as $row) {
                $summary[(string) $row['status']] = (int) $row['c'];
            }

            $latest = Database::run(
                "SELECT finished_at, created_at FROM photo_edit_jobs ORDER BY COALESCE(finished_at, created_at) DESC LIMIT 1"
            )->fetch();
            if ($latest) {
                $summary['latest'] = $latest['finished_at'] ?? $latest['created_at'];
            }
        } catch (\Throwable $e) {
            // Older installations without the table yet.
        }

        $summary['service_active'] = (bool) @file_exists('/etc/systemd/system/gallery-photo-edit.service')
            && @filesize('/etc/systemd/system/gallery-photo-edit.service') > 0;

        return $summary;
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

    /**
     * @return array<int, array{id:string,schedule:string,desc:string,lastRun:?string,lastTs:?int,ok:bool,note:string}>
     */
    private function cronJobs(): array
    {
        $logs = $this->storage . '/logs';
        $now  = time();

        $lastRun = static function (string $file) use ($now): ?array {
            if (!is_file($file)) {
                return [null, null];
            }
            $ts = (int) filemtime($file);
            return [$ts, date('Y-m-d H:i:s', $ts)];
        };

        [$hkTs, $hkAt] = $lastRun($logs . '/cron.log');
        $hkFresh   = $hkTs !== null && ($now - $hkTs) <= 90 * 60;
        $hkNote    = $this->lastLineSummary($logs . '/cron.log');

        [$bpTs, $bpAt] = $lastRun($logs . '/backup.log');
        $bpFailed  = is_file($this->backupDir . '/.failed');
        $bpSync    = $this->lastSyncStatus();
        $bpOk      = $bpTs !== null && !$bpFailed; // archive run completed
        $bpNote    = $bpFailed ? 'last run reported failure' : '';
        if ($bpSync) {
            $syncOk = !empty($bpSync['ok']) && (int) ($bpSync['sync_rc'] ?? 1) === 0;
            // The archive itself is fine; offsite sync health is separate detail.
            $bpNote = ($bpNote ? $bpNote . ' · ' : '') . 'offsite sync ' . ($syncOk ? 'OK' : 'FAILED')
                     . (isset($bpSync['at']) ? ' (' . $bpSync['at'] . ')' : '');
        }

        [$dlTs, $dlAt] = $lastRun($logs . '/drill.log');
        $dlLine  = $this->lastLineSummary($logs . '/drill.log');
        $dlOk    = $dlLine !== '' && stripos($dlLine, 'OK') !== false;

        [$apTs, $apAt] = $lastRun($logs . '/autopost.log');
        $apFail  = $this->autopostRecentFailure($logs . '/autopost.log');

        $jobs = [
            [
                'id'       => 'housekeeping',
                'schedule' => 'every 15 minutes',
                'desc'     => 'Expire overdue subscriptions, purge stale staging dirs, prune old backups, snapshot storage',
                'lastRun'  => $hkAt,
                'lastAgo'  => $this->relativeAge($hkTs),
                'ok'       => $hkFresh,
                'note'     => $hkNote,
            ],
            [
                'id'       => 'autopost',
                'schedule' => 'every minute',
                'desc'     => 'Publish queued auto-posts to X/Reddit once their scheduled time passes',
                'lastRun'  => $apAt,
                'lastAgo'  => $this->relativeAge($apTs),
                'ok'       => $apTs !== null && $apFail === '',
                'note'     => $apFail,
            ],
            [
                'id'       => 'backup',
                'schedule' => 'daily at 03:00',
                'desc'     => 'Full DB + media archive, split into 4 GB parts and synced offsite',
                'lastRun'  => $bpAt,
                'lastAgo'  => $this->relativeAge($bpTs),
                'ok'       => $bpOk,
                'note'     => $bpNote,
            ],
            [
                'id'       => 'restore-drill',
                'schedule' => 'weekly, Sundays at 04:00',
                'desc'     => 'Restore a recent backup into a scratch DB to prove backups are restorable',
                'lastRun'  => $dlAt,
                'lastAgo'  => $this->relativeAge($dlTs),
                'ok'       => $dlOk,
                'note'     => $dlLine,
            ],
        ];

        return $jobs;
    }

    private function relativeAge(?int $ts): string
    {
        if ($ts === null) {
            return 'never';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' min ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' hr ago';
        }
        return (int) floor($diff / 86400) . 'd ago';
    }

    private function lastLineSummary(string $file): string
    {
        if (!is_file($file)) {
            return '';
        }
        $size = filesize($file);
        $fh   = fopen($file, 'r');
        fseek($fh, max(0, $size - 512));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        $lines = array_filter(explode("\n", $tail), static fn (string $l): bool => trim($l) !== '');
        $last  = end($lines);
        return trim((string) preg_replace('/\s+/', ' ', trim((string) $last)));
    }

    private function autopostRecentFailure(string $file): string
    {
        if (!is_file($file) || (time() - (int) filemtime($file)) > 86400) {
            return ''; // aged out (daily cron) or no log yet
        }
        $size = filesize($file);
        $fh = fopen($file, 'r');
        fseek($fh, max(0, $size - 4096));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        if (preg_match('/failed: ([^\r\n]+)/', $tail, $m)) {
            return 'last autopost failed: ' . trim($m[1]);
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Cron scheduling
    // ------------------------------------------------------------------

    private function cronSchedulesFile(): string
    {
        return $this->storage . '/cron/schedules.json';
    }

    /**
     * Desired cron schedules. Defaults match the documented install :
     * housekeeping every 15 min, autopost every minute, backup daily 03:00,
     * restore-drill weekly Sunday 04:00. Stored JSON overrides, if present.
     */
    private function cronSchedule(): array
    {
        $default = [
            'housekeeping'   => ['every_minutes' => 15],
            'autopost'       => ['every_minutes' => 1],
            'backup'         => ['hour' => 3, 'minute' => 0],
            'restore-drill'  => ['dow' => 0, 'hour' => 4, 'minute' => 0],
        ];
        $file = $this->cronSchedulesFile();
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                foreach ($default as $id => $fields) {
                    foreach ($fields as $k => $v) {
                        if (isset($data[$id][$k]) && is_numeric($data[$id][$k])) {
                            $default[$id][$k] = (int) $data[$id][$k];
                        }
                    }
                }
            }
        }
        return $default;
    }

    /**
     * Super-admin only: persist the requested cron schedule for ONE job and
     * apply the resulting /etc/cron.d/ entries via the scoped root helper,
     * then restart the workers. Only the submitted job's fields change; the
     * other jobs keep their stored schedules.
     */
    public function saveCronSchedule(string $job): void
    {
        Auth::requirePermission('logs');
        $user = Auth::user();
        $isSuper = is_array($user) && ($user['role'] ?? '') === 'super_admin';

        if (!$isSuper) {
            $this->flash('error', 'Only the super admin can change cron schedules.');
            $this->redirect('/admin/system');
        }

        $valid = ['housekeeping', 'autopost', 'backup', 'restore-drill'];
        if (!in_array($job, $valid, true)) {
            $this->flash('error', 'Unknown cron job.');
            $this->redirect('/admin/system');
        }

        $req   = $this->request;
        $clamp = static fn (int $v, int $min, int $max): int => min($max, max($min, $v));
        $sched = $this->cronSchedule();

        switch ($job) {
            case 'housekeeping':
                $sched['housekeeping'] = ['every_minutes' => $clamp((int) $req->post('cron_housekeeping_min', 15), 1, 1440)];
                break;
            case 'autopost':
                $sched['autopost'] = ['every_minutes' => $clamp((int) $req->post('cron_autopost_min', 1), 1, 1440)];
                break;
            case 'backup':
                $sched['backup'] = [
                    'hour'   => $clamp((int) $req->post('cron_backup_hour', 3), 0, 23),
                    'minute' => $clamp((int) $req->post('cron_backup_minute', 0), 0, 59),
                ];
                break;
            case 'restore-drill':
                $sched['restore-drill'] = [
                    'dow'    => $clamp((int) $req->post('cron_drill_dow', 0), 0, 6),
                    'hour'   => $clamp((int) $req->post('cron_drill_hour', 4), 0, 23),
                    'minute' => $clamp((int) $req->post('cron_drill_minute', 0), 0, 59),
                ];
                break;
        }

        // Persist the declaration (www-data writable; root applies it).
        $dir = dirname($this->cronSchedulesFile());
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $written = @file_put_contents(
            $this->cronSchedulesFile(),
            json_encode($sched, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        if ($written === false) {
            $this->flash('error', 'Could not write the cron schedule file (permissions?).');
            $this->redirect('/admin/system');
        }

        // Apply via the scoped root helper: writes /etc/cron.d and restarts.
        // Invoke the CLI binary explicitly (PHP_BINARY is the FPM master under
        // mod_php/php-fpm and cannot be exec'd to run a script).
        $phpBin = '/usr/bin/php';
        $cmd    = 'sudo -n ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($this->root . '/bin/apply_cron.php') . ' 2>&1';
        exec($cmd, $outLines, $rc);

        $labels = [
            'housekeeping'  => 'Housekeeping',
            'autopost'      => 'Auto-poster',
            'backup'        => 'Backup',
            'restore-drill' => 'Restore drill',
        ];
        $label = $labels[$job] ?? $job;

        if ($rc === 0) {
            AuditLog::record($user['id'] ?? null, 'update', 'system_cron_schedule', null,
                'Cron schedule saved + applied for ' . $job . ': ' . json_encode($sched[$job]));
            $this->flash('success', $label . ' schedule saved and applied. Workers restarted.');
        } else {
            $this->flash('error', 'Schedule saved but could NOT be applied — ' . trim(implode(' ', $outLines))
                . ' (check the www-data sudoers rule for apply_cron.php).');
        }

        $this->redirect('/admin/system');
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

    /**
     * Send a test email via the configured SMTP server and report the result.
     */
    public function smtpTest(): void
    {
        $to = \App\Core\Mailer::adminEmail();

        if ($to === '') {
            $this->flash('error', 'No admin email configured — set ADMIN_EMAIL in .env first.');
            $this->redirect('/admin/system');
            return;
        }

        $subject = '[gallery] SMTP test — ' . date('Y-m-d H:i:s');
        $body    = "This is a test email sent from the gallery admin panel.\n\n"
            . "Server: " . gethostname() . "\n"
            . "Time: " . date('Y-m-d H:i:s e') . "\n"
            . "Mail host: " . env_value('MAIL_HOST', '(not set)') . "\n"
            . "Mail user: " . env_value('MAIL_USERNAME', '(not set)') . "\n";

        $ok = \App\Core\Mailer::send($to, $subject, $body);

        AuditLog::record(
            Auth::user()['id'] ?? null,
            'update',
            'system_smtp_test',
            null,
            'SMTP test email to ' . $to . ': ' . ($ok ? 'sent' : 'failed')
        );

        if ($ok) {
            $this->flash('success', 'Test email sent to ' . $to . ' — check your inbox (and spam folder).');
        } else {
            $this->flash('error', 'SMTP test failed. Check MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD in .env and that the server can reach the SMTP host.');
        }

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

        foreach ((glob($this->backupDir . '/*.tar.gz') ?: []) as $file) {
            $name       = basename($file);
            $out[$name] = [
                'name'  => $name,
                'size'  => (int) filesize($file),
                'time'  => (int) filemtime($file),
                'parts' => 0,
            ];
        }

        // Media archives are stored as split .part-NN chunks; present each
        // chunked set as a single logical archive.
        foreach ((glob($this->backupDir . '/*.tar.gz.part-*') ?: []) as $file) {
            $base = basename($file);
            $name = substr($base, 0, strrpos($base, '.part-'));

            if (!isset($out[$name])) {
                $out[$name] = ['name' => $name, 'size' => 0, 'time' => 0, 'parts' => 0];
            }

            $out[$name]['size'] += (int) filesize($file);
            $out[$name]['time']  = max($out[$name]['time'], (int) filemtime($file));
            $out[$name]['parts']++;
        }

        foreach ((glob($this->backupDir . '/*.sql.gz') ?: []) as $file) {
            $name       = basename($file);
            $out[$name] = [
                'name'  => $name,
                'size'  => (int) filesize($file),
                'time'  => (int) filemtime($file),
                'parts' => 0,
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['time'] <=> $a['time']);

        return array_values($out);
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
# Split the media archive into 4 GiB parts so offsite sync can run
# several streams at once. Reassemble with:
#   cat <name>.tar.gz.part-* > <name>.tar.gz
split -b 4G -d "\$TARGET" "\$TARGET.part-"
PARTS=\$(ls -1 "\$TARGET".part-* | wc -l)
test "\$PARTS" -ge 1 || { echo "\$(date "+%F %T") split failed for \$TARGET" >> {BACKUPDIR}/.failed; exit 1; }
(cd {BACKUPDIR} && sha256sum \$(basename "\$TARGET").part-* > \$(basename "\$TARGET").sha256)
rm -f "\$TARGET"
SYNC_RC=0
{SYNCBLOCK}
printf '{"ok":true,"at":"%s","file":"%s","parts":%d,"db":"%s","sync_rc":%s}\\n' "\$(date +%FT%T)" "\$(basename "\$TARGET")" "\$PARTS" "\$(basename "\$SQLT")" "\$SYNC_RC" > {BACKUPDIR}/.last_sync
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
        $name = basename($file);
        $path = $this->backupDir . '/' . $name;

        if (!is_file($path)) {
            // A split archive: stream every part back as one continuous
            // file so the browser saves a ready-to-extract tar.gz.
            $parts = (glob($this->backupDir . '/' . $name . '.part-*') ?: []);
            sort($parts);

            if ($parts !== []) {
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . array_sum(array_map('filesize', $parts)));

                foreach ($parts as $part) {
                    readfile($part);
                }

                exit;
            }

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
        $name = basename($file);
        $path = $this->backupDir . '/' . $name;

        if (is_file($path) && @unlink($path)) {
            AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_backup', null,
                'Deleted backup ' . $name);
            $this->flash('success', 'Backup deleted.');

            $this->redirect('/admin/system');
        }

        // Split archive base name: remove every part plus its checksum file.
        $related = array_merge(
            (glob($this->backupDir . '/' . $name . '.part-*') ?: []),
            (glob($this->backupDir . '/' . $name . '.sha256') ?: [])
        );

        if ($related !== []) {
            foreach ($related as $relatedFile) {
                @unlink($relatedFile);
            }

            AuditLog::record(Auth::user()['id'] ?? null, 'delete', 'system_backup', null,
                'Deleted backup ' . $name . ' (' . count($related) . ' files)');
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
        // Name capture must not be greedy or it backtracks down to a single
        // letter of the table name; body runs to the last ')' before ';'.
        preg_match_all(
            '/CREATE TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?\s*\(([^;]*)\)\s*[^;]*;/is',
            $sql,
            $tables,
            PREG_SET_ORDER
        );

        foreach ($tables as $table) {
            $name = $table[1];
            $cols = [];

            foreach (explode("\n", $table[2]) as $line) {
                $line = trim(trim($line), ",");
                if ($line === '' || preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT|SPATIAL|CHECK|FOREIGN)\b/i', $line)) {
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
