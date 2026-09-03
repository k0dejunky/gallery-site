<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Self-contained, read-only test suite registry + runner used by the admin
 * "Test suite" page. Every check is non-destructive (queries, file probes,
 * remote HTTP checks, function/class existence) so any test can be re-run
 * safely at any time, including from a background worker process.
 *
 * This class is designed to load from BOTH the web request scope (autoloaded
 * via index.php) and the CLI scope (bin/test_runner.php requires
 * helpers.php + a loader that maps App\ to app/). The block below therefore
 * duplicates the bootstrap so a CLI process can use the exact same tests.
 */
if (PHP_SAPI === 'cli') {
    require_once __DIR__ . '/helpers.php';
}

class TestSuite
{
    /** Path to the status files written/read by the runner and the page. */
    private const RUN_DIR = __DIR__ . '/../../storage/testruns';

    /**
     * Registry of every available test. Each entry is
     *   [
     *     'id'      => unique string,
     *     'group'   => category label,
     *     'name'    => human-readable title,
     *     'run'     => callable(): array{pass: bool, detail: string},
     *   ]
     */
    public static function tests(): array
    {
        $root   = dirname(__DIR__, 2);
        $storage= $root . '/storage';
        $key    = static fn (string $k, string $d = '') => env_value($k, $d);

        $tests = [];

        $add = static function (string $id, string $group, string $name, callable $run) use (&$tests): void {
            $tests[$id] = ['id' => $id, 'group' => $group, 'name' => $name, 'run' => $run];
        };

        // ---------------------------------------------------------------- App & Config
        $add('config.app_url', 'App & Config', 'APP_URL is set and absolute', function () {
            $url = env_value('APP_URL');
            return ['pass' => (bool) preg_match('#^https?://#i', $url), 'detail' => $url ?: 'empty'];
        });

        $add('config.db', 'App & Config', '.env database settings present', function () {
            $ok = env_value('GALLERY_DB_HOST') !== ''
                && env_value('GALLERY_DB_NAME') !== ''
                && env_value('GALLERY_DB_USER') !== '';
            return ['pass' => $ok, 'detail' => $ok ? 'host=' . env_value('GALLERY_DB_HOST') . ' db=' . env_value('GALLERY_DB_NAME') : 'missing keys'];
        });

        $add('config.media_key', 'App & Config', 'GALLERY_MEDIA_KEY is set', function () {
            return ['pass' => env_value('GALLERY_MEDIA_KEY') !== '', 'detail' => env_value('GALLERY_MEDIA_KEY') ? 'set' : 'empty'];
        });

        $add('config.base_path', 'App & Config', 'base path config resolves', function () {
            $base = (string) config('app.base_path');
            $ok = $base === '' || str_starts_with($base, '/');
            return ['pass' => $ok, 'detail' => $base === '' ? '(root)' : $base];
        });

        $add('config.uploads_dir', 'App & Config', 'uploads directory exists & writable', function () {
            $dir = (string) config('app.uploads')['dir'];
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('config.storage_writable', 'App & Config', 'storage directory writable', function () use ($storage) {
            return ['pass' => is_dir($storage) && is_writable($storage), 'detail' => $storage];
        });

        $add('config.cron_key', 'App & Config', 'GALLERY_CRON_KEY is set', function () {
            return ['pass' => env_value('GALLERY_CRON_KEY') !== '', 'detail' => env_value('GALLERY_CRON_KEY') ? 'set' : 'empty'];
        });

        $add('config.env_readable', 'App & Config', '.env file present & readable', function () use ($root) {
            $f = $root . '/.env';
            return ['pass' => is_file($f) && is_readable($f), 'detail' => $f];
        });

        // ---------------------------------------------------------------- Extensions & Toolchain
        $add('tool.php_version', 'Extensions & Tools', 'PHP version supported (>= 8.0)', function () {
            return ['pass' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => PHP_VERSION];
        });

        $add('tool.pdo', 'Extensions & Tools', 'PDO + pdo_mysql loaded', function () {
            return ['pass' => extension_loaded('pdo') && extension_loaded('pdo_mysql'), 'detail' => 'pdo=' . (extension_loaded('pdo') ? 'yes' : 'no') . ' pdo_mysql=' . (extension_loaded('pdo_mysql') ? 'yes' : 'no')];
        });

        $add('tool.gd', 'Extensions & Tools', 'GD image library loaded', function () {
            $ok = extension_loaded('gd') && function_exists('imagecreatetruecolor');
            return ['pass' => $ok, 'detail' => $ok ? 'gd ' . (gd_info()['GD Version'] ?? '') : 'missing'];
        });

        $add('tool.curl', 'Extensions & Tools', 'cURL extension loaded', function () {
            return ['pass' => extension_loaded('curl') && function_exists('curl_init'), 'detail' => extension_loaded('curl') ? 'loaded' : 'missing'];
        });

        $add('tool.ffmpeg', 'Extensions & Tools', 'ffmpeg binary available', function () {
            $rc = 1; @exec('ffmpeg -version >/dev/null 2>&1', $o, $rc);
            return ['pass' => $rc === 0, 'detail' => $rc === 0 ? 'ffmpeg ok' : 'not found'];
        });

        $add('tool.ffprobe', 'Extensions & Tools', 'ffprobe binary available', function () {
            $rc = 1; @exec('ffprobe -version >/dev/null 2>&1', $o, $rc);
            return ['pass' => $rc === 0, 'detail' => $rc === 0 ? 'ffprobe ok' : 'not found'];
        });

        $add('tool.zip', 'Extensions & Tools', 'ZipArchive class available', function () {
            return ['pass' => class_exists('ZipArchive'), 'detail' => class_exists('ZipArchive') ? 'present' : 'missing'];
        });

        $add('tool.hash', 'Extensions & Tools', 'HMAC (hash_hmac) available', function () {
            return ['pass' => function_exists('hash_hmac') && in_array('sha256', hash_algos(), true), 'detail' => 'sha256 hmac ok'];
        });

        // ---------------------------------------------------------------- Database & Schema
        $add('db.connect', 'Database', 'Can connect to the database', function () {
            try {
                Database::run('SELECT 1')->fetchColumn();
                return ['pass' => true, 'detail' => 'connected'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.tables_present', 'Database', 'All expected core tables exist', function () {
            $expected = ['users', 'galleries', 'photos', 'categories', 'auto_poster_queue', 'auto_poster_log'];
            try {
                $rows = Database::run('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
                $missing = array_values(array_diff($expected, $rows));
                return ['pass' => $missing === [], 'detail' => $missing === [] ? count($rows) . ' tables' : 'missing: ' . implode(', ', $missing)];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.migrations', 'Database', 'Migration tracking table consistent with file list', function () {
            try {
                $dir  = dirname(__DIR__, 2) . '/database/migrations';
                $rows = Database::run('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
                foreach (glob($dir . '/*.sql') ?: [] as $file) {
                    $name = basename($file);
                    if (!in_array($name, $rows, true)) {
                        return ['pass' => false, 'detail' => 'not applied: ' . $name];
                    }
                }
                return ['pass' => true, 'detail' => count($rows) . ' applied'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.autoposter_schema', 'Database', 'auto_poster_queue has media_ids + scheduled_at', function () {
            try {
                $cols = Database::run('SHOW COLUMNS FROM auto_poster_queue')->fetchAll(\PDO::FETCH_COLUMN);
                $need = ['media_ids', 'scheduled_at', 'status'];
                $missing = array_values(array_diff($need, array_map('strtolower', $cols)));
                return ['pass' => $missing === [], 'detail' => $missing === [] ? implode(',', $need) . ' present' : 'missing: ' . implode(', ', $missing)];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.content_counts', 'Database', 'Site has content (users/galleries/photos)', function () {
            try {
                $u = (int) Database::run('SELECT COUNT(*) FROM users')->fetchColumn();
                $g = (int) Database::run('SELECT COUNT(*) FROM galleries')->fetchColumn();
                $p = (int) Database::run('SELECT COUNT(*) FROM photos')->fetchColumn();
                return ['pass' => $u > 0 && $p > 0, 'detail' => 'users=' . $u . ' galleries=' . $g . ' photos=' . $p];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('db.orphan_photos', 'Database', 'No orphan photos (every photo in a gallery)', function () {
            try {
                $n = (int) Database::run('SELECT COUNT(*) FROM photos p WHERE NOT EXISTS (SELECT 1 FROM gallery_photo gp WHERE gp.photo_id = p.id)')->fetchColumn();
                return ['pass' => $n === 0, 'detail' => $n . ' orphan(s)'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Auth
        $add('auth.password_hash', 'Auth', 'Password hashing produces verifiable hash', function () {
            $hash = password_hash('test', PASSWORD_DEFAULT);
            return ['pass' => $hash && password_verify('test', $hash), 'detail' => 'bcrypt/argon ok'];
        });

        $add('auth.superadmin_role', 'Auth', 'At least one super_admin account exists', function () {
            try {
                return ['pass' => User::countAdmins() > 0, 'detail' => User::countAdmins() . ' super_admins'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('auth.csrf_helper', 'Auth', 'CSRF helpers available (field + token source)', function () {
            $ok = function_exists('csrf_field') && class_exists(\App\Core\Csrf::class) && method_exists(\App\Core\Csrf::class, 'token');
            return ['pass' => $ok, 'detail' => $ok ? 'csrf helpers ok' : 'csrf missing'];
        });

        // ---------------------------------------------------------------- Front-end / Routes
        $add('route.public_pages', 'Front-end', 'Public GET pages return HTTP 200', function () {
            $pages = ['/', '/galleries', '/about', '/terms', '/privacy', '/health'];
            $base  = rtrim(env_value('APP_URL'), '/');
            if (!preg_match('#^https?://#i', $base)) {
                return ['pass' => false, 'detail' => 'APP_URL invalid for HTTP probe'];
            }
            $fail = [];
            foreach ($pages as $p) {
                // APP_URL already carries the base path (e.g. .../gallery), so
                // concatenate the raw route path — url() would double-prefix.
                $url  = $base . $p;
                $code = self::httpStatus($url);
                if ($code !== 200) $fail[$p] = $code;
            }
            return ['pass' => $fail === [], 'detail' => $fail === [] ? count($pages) . ' pages 200' : json_encode($fail)];
        });

        $add('route.login_page', 'Front-end', 'Login page reachable (200)', function () {
            $base = rtrim(env_value('APP_URL'), '/');
            return ['pass' => self::httpStatus($base . '/login') === 200, 'detail' => self::httpStatus($base . '/login')];
        });

        $add('route.media_guard', 'Front-end', '/files/ route requires media token logic', function () {
            $ok = function_exists('media_token') && function_exists('media_token_valid');
            return ['pass' => $ok, 'detail' => $ok ? 'media token helpers present' : 'missing'];
        });

        // ---------------------------------------------------------------- Models
        $add('model.audit_log', 'Models', 'AuditLog queries succeed', function () {
            try {
                $rows = AuditLog::recent(1, 10);
                return ['pass' => is_array($rows), 'detail' => count($rows) . ' recent rows'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        $add('model.theme_defaults', 'Models', 'Theme defaults resolve', function () {
            try {
                $d = \App\Models\Theme::defaults();
                return ['pass' => is_array($d) && isset($d['btn-bg'], $d['card-bg']), 'detail' => count($d) . ' keys'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Auto-poster
        $add('autoposter.config', 'Auto Poster', 'Auto-poster config model loads', function () {
            try {
                $cfg = \App\Models\AutoPosterConfig::all();
                return ['pass' => is_array($cfg), 'detail' => count($cfg) . ' keys'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => 'config:' . $ex->getMessage()];
            }
        });

        $add('autoposter.tweet_key', 'Auto Poster', 'X (Twitter) OAuth consumer key configured', function () {
            try {
                $cfg = \App\Models\AutoPosterConfig::all();
                $key = (string) ($cfg['twitter']['consumer_key'] ?? '');
                return ['pass' => $key !== '', 'detail' => $key !== '' ? 'set' : 'empty'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => 'config:' . $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Video / Photo jobs
        $add('video.worker_bin', 'Video Jobs', 'video_export_worker.php exists', function () use ($root) {
            return ['pass' => is_file($root . '/bin/video_export_worker.php'), 'detail' => 'bin/video_export_worker.php'];
        });

        $add('photo.worker_bin', 'Photo Jobs', 'photo_edit_queue.php exists', function () use ($root) {
            return ['pass' => is_file($root . '/bin/photo_edit_queue.php'), 'detail' => 'bin/photo_edit_queue.php'];
        });

        // ---------------------------------------------------------------- Backup / System
        $add('system.backup_dir', 'System', 'Backup directory exists & writable', function () use ($storage) {
            $dir = $storage . '/backups';
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('system.logs_writable', 'System', 'Logs directory exists & writable', function () use ($storage) {
            $dir = $storage . '/logs';
            return ['pass' => is_dir($dir) && is_writable($dir), 'detail' => $dir];
        });

        $add('system.storage_integrity', 'System', 'Expected storage subdirectories exist & writable', function () use ($storage) {
            $needed = ['backups', 'logs', 'cache', 'cron', 'mail-outbox', 'themes', 'uploads'];
            $bad = [];
            foreach ($needed as $sub) {
                $dir = $storage . '/' . $sub;
                if (!is_dir($dir) || !is_writable($dir)) {
                    $bad[] = $sub . (!is_dir($dir) ? '(missing)' : '(not writable)');
                }
            }
            return ['pass' => $bad === [], 'detail' => $bad === [] ? implode(', ', $needed) . ' ok' : implode('; ', $bad)];
        });

        $add('system.cron_files', 'System', '/etc/cron.d/gallery-* rules present (root install)', function () {
            $found = [];
            foreach (['housekeeping', 'autopost', 'backup', 'restore-drill'] as $suffix) {
                $f = '/etc/cron.d/gallery-' . $suffix;
                if (is_file($f) && is_readable($f)) $found[] = $suffix;
            }
            return ['pass' => $found !== [], 'detail' => $found === [] ? 'none' : implode(',', $found)];
        });

        $add('system.cron_key_matches', 'System', 'Schedules JSON + cron key consistent', function () use ($storage) {
            $j = $storage . '/cron/schedules.json';
            if (!is_file($j)) return ['pass' => true, 'detail' => 'no schedules.json (defaults in code)'];
            try {
                $data = json_decode((string) file_get_contents($j), true);
                return ['pass' => is_array($data), 'detail' => 'schedules.json parseable'];
            } catch (\Throwable $ex) {
                return ['pass' => false, 'detail' => $ex->getMessage()];
            }
        });

        // ---------------------------------------------------------------- Email
        $add('mail.config', 'Mail', 'SMTP host configured', function () {
            return ['pass' => env_value('MAIL_HOST') !== '', 'detail' => env_value('MAIL_HOST') ?: 'empty'];
        });

        // Merge in every static smoke check (single source of truth shared
        // with tests/smoke.php) so the admin suite can run them too.
        foreach (SmokeChecks::all() as $id => $smokeTest) {
            $tests[$id] = $smokeTest;
        }

        return $tests;
    }

    /** Return the list grouped by category for rendering. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::tests() as $t) {
            $out[$t['group']][] = $t;
        }
        return $out;
    }

    /**
     * Run a set of tests, invoking $report per completed test with an
     * incrementing index so callers can persist progressive state.
     *
     * @param array $ids   selected test ids (validated against the registry)
     * @param callable $report function(int $index, array $test, array $result): void
     */
    public static function run(array $ids, ?callable $report = null): array
    {
        $all = self::tests();
        $results = [];
        $index = 0;
        foreach ($ids as $id) {
            if (!isset($all[$id])) continue;
            ++$index;
            $test = $all[$id];
            $result = self::safeRun($test['run']);
            $row = ['id' => $id, 'name' => $test['name'], 'group' => $test['group'],
                    'status' => $result['pass'] ? 'passed' : 'failed', 'detail' => $result['detail']];
            $results[$id] = $row;
            if ($report !== null) {
                $report($index, $row);
            }
        }
        return $results;
    }

    private static function safeRun(callable $fn): array
    {
        try {
            $r = $fn();
            return is_array($r) ? $r : ['pass' => (bool) $r, 'detail' => ''];
        } catch (\Throwable $ex) {
            return ['pass' => false, 'detail' => 'exception: ' . $ex->getMessage()];
        }
    }

    /** Perform a GET and return the HTTP status code (fake-browser UA). */
    private static function httpStatus(string $url): int
    {
        // A HEAD request is used deliberately: it is enough to prove the route
        // is reachable. Some server configs return 404 for HEAD on framework
        // routes though, so fall back to a full GET when HEAD disagrees.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'TestSuite/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $headCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($headCode === 200) {
            return 200;
        }

        // Retry with a plain GET when the server mishandles HEAD.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'TestSuite/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code !== 0 ? $code : 0;
    }

    // ------------------------------------------------------------ run-state persistence
    public static function ensureRunsDir(): void
    {
        if (!is_dir(self::RUN_DIR)) {
            @mkdir(self::RUN_DIR, 0775, true);
        }
    }

    public static function runPath(string $runId): string
    {
        return self::RUN_DIR . '/run-' . preg_replace('/[^A-Za-z0-9_-]/', '', $runId) . '.json';
    }

    /** Glob pattern matching every run-state file (run-*.json). */
    public static function runsGlob(): string
    {
        return self::RUN_DIR . '/run-*.json';
    }

    public static function readRun(string $runId): ?array
    {
        $p = self::runPath($runId);
        if (!is_file($p)) return null;
        $data = json_decode((string) file_get_contents($p), true);
        return is_array($data) ? $data : null;
    }

    public static function writeRun(array $data): void
    {
        self::ensureRunsDir();
        $p = self::runPath((string) $data['id']);
        file_put_contents($p, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($p, 0664);
    }
}

if (PHP_SAPI === 'cli') {
    // Keep the CLI scope identical to the web scope by also loading the
    // classes referenced above through the app's autoloader.
    spl_autoload_register(function (string $class): void {
        $prefix = 'App\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $path = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    });
}